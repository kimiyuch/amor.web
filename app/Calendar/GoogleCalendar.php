<?php

namespace App\Calendar;

use App\Models\Setting;
use App\Models\Shift;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

/**
 * Writes the shifts into one Google calendar. Amor connects once (OAuth with offline
 * access) and keeps the refresh token, encrypted, on the server; every device then
 * benefits. Each date has a fixed event ID, so saving a roster again updates its events
 * and removes the ones whose shift is gone.
 */
final class GoogleCalendar
{
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';

    private const API = 'https://www.googleapis.com/calendar/v3';

    private const SCOPES = [
        'https://www.googleapis.com/auth/calendar.calendarlist.readonly', // to choose the calendar
        'https://www.googleapis.com/auth/calendar.events',
    ];

    // Retries when Google asks to slow down (it limits bursts per user).
    private const RETRIES = 5;

    public static function configured(): bool
    {
        return filled(config('services.google.client_id')) && filled(config('services.google.client_secret'));
    }

    public static function connected(): bool
    {
        return self::configured() && filled(self::settings()['refresh_token'] ?? null);
    }

    public static function calendarId(): ?string
    {
        return self::settings()['calendar_id'] ?? null;
    }

    public static function calendarName(): ?string
    {
        return self::settings()['calendar_name'] ?? null;
    }

    public static function authUrl(string $state): string
    {
        return self::AUTH_URL.'?'.http_build_query([
            'client_id' => config('services.google.client_id'),
            'redirect_uri' => route('google.callback'),
            'response_type' => 'code',
            'scope' => implode(' ', self::SCOPES),
            'access_type' => 'offline',
            'prompt' => 'consent', // always hands out a refresh token
            'include_granted_scopes' => 'true',
            'state' => $state,
        ]);
    }

    /** Exchanges the code from the consent screen and starts with the account's main calendar. */
    public static function connect(string $code): void
    {
        $response = Http::asForm()->post(self::TOKEN_URL, [
            'code' => $code,
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'redirect_uri' => route('google.callback'),
            'grant_type' => 'authorization_code',
        ]);
        if (! $response->ok() || ! $response->json('refresh_token')) {
            throw new GoogleCalendarException('Google didn\'t accept the sign-in. Please try again.');
        }
        Cache::put('google.access_token', $response->json('access_token'), now()->addSeconds($response->json('expires_in', 3600) - 60));
        self::store(['refresh_token' => Crypt::encryptString($response->json('refresh_token'))]);

        $primary = collect(self::calendars())->firstWhere('primary', true) ?? collect(self::calendars())->first();
        self::store(['calendar_id' => $primary['id'] ?? 'primary', 'calendar_name' => $primary['name'] ?? 'Google Calendar']);
    }

    public static function disconnect(): void
    {
        $token = self::refreshToken();
        if ($token) {
            Http::asForm()->post(self::REVOKE_URL, ['token' => $token]); // best effort
        }
        Cache::forget('google.access_token');
        Setting::put('google', null);
    }

    /** @return list<array{id: string, name: string, primary: bool}> calendars the account may write to */
    public static function calendars(): array
    {
        $response = self::api()->get(self::API.'/users/me/calendarList', ['minAccessRole' => 'writer']);
        self::check($response, 'Loading your Google calendars');

        return collect($response->json('items', []))
            ->map(fn (array $c) => ['id' => $c['id'], 'name' => $c['summaryOverride'] ?? $c['summary'] ?? $c['id'], 'primary' => (bool) ($c['primary'] ?? false)])
            ->sortByDesc('primary')
            ->values()
            ->all();
    }

    public static function useCalendar(string $id, string $name): void
    {
        self::store(['calendar_id' => $id, 'calendar_name' => $name]);
    }

    /**
     * Makes the calendar match the saved shifts from $from to $to: one event per shift, and
     * none of Amor's events on days without one. Only touches what needs to change.
     *
     * @return array{saved: int, removed: int}
     */
    public static function sync(string $from, string $to, ?string $calendarId = null): array
    {
        $calendarId ??= self::calendarId();
        $events = ShiftCalendar::fromDatabase()
            ->events(Shift::whereBetween('date', [$from, $to])->get())
            ->keyBy('date');
        $existing = self::existingDates($calendarId, $from, $to);
        $base = self::eventsUrl($calendarId);
        $removed = 0;

        foreach ($events as $date => $event) {
            if (in_array($date, $existing, true)) {
                $response = self::send('PUT', "{$base}/".self::eventId($date), self::body($event));
            } else {
                $response = self::send('POST', $base, self::body($event));
                if ($response->status() === 409) {
                    // The ID is taken by an event deleted earlier: bring that one back.
                    $response = self::send('PUT', "{$base}/".self::eventId($date), self::body($event));
                }
            }
            self::check($response, 'Saving the shift on '.self::day($date));
        }

        foreach (array_diff($existing, $events->keys()->all()) as $date) {
            $response = self::send('DELETE', "{$base}/".self::eventId($date));
            if (! in_array($response->status(), [404, 410], true)) {
                self::check($response, 'Removing the event on '.self::day($date));
                $removed++;
            }
        }

        return ['saved' => $events->count(), 'removed' => $removed];
    }

    /** Removes Amor's events from $from to $to, e.g. from a calendar that is no longer used. */
    public static function clear(string $calendarId, string $from, string $to): void
    {
        foreach (self::existingDates($calendarId, $from, $to) as $date) {
            $response = self::send('DELETE', self::eventsUrl($calendarId).'/'.self::eventId($date));
            if (! in_array($response->status(), [404, 410], true)) {
                self::check($response, 'Removing the event on '.self::day($date));
            }
        }
    }

    /**
     * Dates from $from to $to on which the calendar has an event made by Amor.
     *
     * @return list<string>
     */
    private static function existingDates(string $calendarId, string $from, string $to): array
    {
        $dates = [];
        $pageToken = null;
        do {
            $response = self::send('GET', self::eventsUrl($calendarId), query: array_filter([
                'privateExtendedProperty' => 'amor=1',
                'timeMin' => ShiftCalendar::addDays($from, -1).'T00:00:00Z',
                'timeMax' => ShiftCalendar::addDays($to, 2).'T00:00:00Z',
                'maxResults' => 2500,
                'pageToken' => $pageToken,
            ]));
            self::check($response, 'Reading the calendar');
            foreach ($response->json('items', []) as $item) {
                if (preg_match('/^amor(\d{4})(\d{2})(\d{2})$/', $item['id'] ?? '', $m)) {
                    $date = "{$m[1]}-{$m[2]}-{$m[3]}";
                    if ($date >= $from && $date <= $to) {
                        $dates[] = $date;
                    }
                }
            }
            $pageToken = $response->json('nextPageToken');
        } while ($pageToken);

        return array_values(array_unique($dates));
    }

    /** Sends one request, waiting and retrying while Google asks to slow down. */
    private static function send(string $method, string $url, array $body = [], array $query = []): Response
    {
        for ($attempt = 1; ; $attempt++) {
            $options = array_filter(['json' => $body ?: null, 'query' => $query ?: null]);
            $response = self::api()->send($method, $url, $options);
            $reason = $response->json('error.errors.0.reason');
            $slowDown = $response->status() === 429 || $response->serverError()
                || ($response->status() === 403 && in_array($reason, ['rateLimitExceeded', 'userRateLimitExceeded'], true));
            if (! $slowDown || $attempt >= self::RETRIES) {
                return $response;
            }
            usleep((2 ** ($attempt - 1)) * 600_000 + random_int(0, 300_000));
        }
    }

    private static function eventsUrl(string $calendarId): string
    {
        return self::API.'/calendars/'.rawurlencode($calendarId).'/events';
    }

    /** "29.10.2026", for messages. */
    private static function day(string $date): string
    {
        return implode('.', array_reverse(explode('-', $date)));
    }

    /** Google event IDs allow a–v and 0–9; one fixed ID per date. */
    public static function eventId(string $date): string
    {
        return 'amor'.str_replace('-', '', $date);
    }

    private static function body(ShiftEvent $event): array
    {
        $timezone = (string) config('amor.timezone');
        $when = fn (string $value) => $event->allDay
            ? ['date' => $value]
            : ['dateTime' => $value.':00', 'timeZone' => $timezone];

        return [
            'id' => self::eventId($event->date),
            'summary' => $event->title,
            'description' => "Code {$event->code} · Amor",
            'status' => 'confirmed',
            'start' => $when($event->start),
            'end' => $when($event->end),
            'transparency' => 'opaque',
            'extendedProperties' => ['private' => ['amor' => '1', 'code' => $event->code]],
        ];
    }

    private static function api()
    {
        return Http::withToken(self::accessToken())->acceptJson()->timeout(20);
    }

    private static function accessToken(): string
    {
        if ($token = Cache::get('google.access_token')) {
            return $token;
        }
        $refresh = self::refreshToken();
        if (! $refresh) {
            throw new GoogleCalendarException('Google Calendar isn\'t connected. Connect it in Settings.');
        }
        $response = Http::asForm()->post(self::TOKEN_URL, [
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'refresh_token' => $refresh,
            'grant_type' => 'refresh_token',
        ]);
        if ($response->json('error') === 'invalid_grant') {
            // Revoked, or the password changed: only a new sign-in helps.
            Setting::put('google', null);
            throw new GoogleCalendarException('Google Calendar was disconnected. Connect it again in Settings.');
        }
        self::check($response, 'Signing in to Google');
        Cache::put('google.access_token', $response->json('access_token'), now()->addSeconds($response->json('expires_in', 3600) - 60));

        return $response->json('access_token');
    }

    private static function check(mixed $response, string $what): void
    {
        if ($response instanceof Response && $response->successful()) {
            return;
        }
        $detail = $response instanceof Response
            ? ($response->json('error.message') ?? $response->json('error_description') ?? 'error '.$response->status())
            : 'no connection';
        throw new GoogleCalendarException("{$what} didn't work ({$detail}).");
    }

    private static function refreshToken(): ?string
    {
        $encrypted = self::settings()['refresh_token'] ?? null;

        return $encrypted ? Crypt::decryptString($encrypted) : null;
    }

    private static function settings(): array
    {
        return (array) Setting::get('google', []);
    }

    private static function store(array $values): void
    {
        Setting::put('google', [...self::settings(), ...$values]);
    }
}
