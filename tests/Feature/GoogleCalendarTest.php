<?php

namespace Tests\Feature;

use App\Calendar\GoogleCalendar;
use App\Models\Scan;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\ShiftCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GoogleCalendarTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, array> events in the fake Google calendars, by "calendar/id" */
    private array $google = [];

    /** Switches for the failure tests. */
    private bool $googleDown = false;

    private bool $tokenRevoked = false;

    /** Requests answered with "Rate Limit Exceeded" before Google gives in. */
    private int $rateLimited = 0;

    /** @var list<string> method and path of every calendar request */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.google.client_id' => 'client', 'services.google.client_secret' => 'secret']);
        Storage::fake('local');
        $this->travelTo('2026-12-01 09:00');

        Http::fake([
            'oauth2.googleapis.com/token' => fn () => $this->tokenRevoked
                ? Http::response(['error' => 'invalid_grant'], 400)
                : Http::response(['access_token' => 'access', 'refresh_token' => 'refresh', 'expires_in' => 3600]),
            'oauth2.googleapis.com/revoke' => Http::response(),
            'www.googleapis.com/calendar/v3/users/me/calendarList*' => Http::response(['items' => [
                ['id' => 'work@group.calendar.google.com', 'summary' => 'Arbeit'],
                ['id' => 'anna@gmail.com', 'summary' => 'anna@gmail.com', 'summaryOverride' => 'Anna', 'primary' => true],
            ]]),
            'www.googleapis.com/calendar/v3/calendars/*' => fn (Request $request) => $this->fakeEvents($request),
        ]);
    }

    /** A tiny Google Calendar: PUT updates (404 if missing), POST creates, DELETE removes. */
    private function fakeEvents(Request $request)
    {
        $limited = Http::response(['error' => ['message' => 'Rate Limit Exceeded', 'errors' => [['reason' => 'rateLimitExceeded']]]], 403);
        if ($this->googleDown) {
            return $limited;
        }
        if ($this->rateLimited > 0) {
            $this->rateLimited--;

            return $limited;
        }
        preg_match('#/calendars/([^/]+)/events(?:/([^/?]+))?#', $request->url(), $m);
        $calendar = rawurldecode($m[1]);
        $this->calls[] = $request->method().' '.($m[2] ?? '');

        if ($request->method() === 'GET') {
            $items = collect($this->google)
                ->filter(fn ($e, $key) => str_starts_with($key, $calendar.'/') && ($e['extendedProperties']['private']['amor'] ?? null) === '1')
                ->map(fn ($e, $key) => ['id' => substr($key, strlen($calendar) + 1)] + $e)
                ->values();

            return Http::response(['items' => $items]);
        }
        $key = $calendar.'/'.($m[2] ?? $request['id']);

        return match ($request->method()) {
            'PUT' => isset($this->google[$key]) ? Http::response($this->google[$key] = $request->data()) : Http::response(['error' => ['message' => 'Not Found']], 404),
            'POST' => isset($this->google[$key]) ? Http::response(['error' => ['message' => 'The requested identifier already exists.']], 409) : Http::response($this->google[$key] = $request->data()),
            'DELETE' => isset($this->google[$key]) ? (function () use ($key) { unset($this->google[$key]); return Http::response(null, 204); })() : Http::response(null, 410),
        };
    }

    private function connect(): void
    {
        Setting::put('google', ['refresh_token' => Crypt::encryptString('refresh'), 'calendar_id' => 'anna@gmail.com', 'calendar_name' => 'Anna']);
        Cache::put('google.access_token', 'access', 3600);
    }

    private function savedScan(array $changes = []): Scan
    {
        $this->signedIn()->post('/scans', ['person' => 'Anna Muster', 'photo' => UploadedFile::fake()->image('roster.jpg', 1600, 1000)]);
        $scan = Scan::latest('id')->first();
        $codes = array_merge(collect($scan->days())->pluck('code', 'date')->all(), $changes);
        $this->signedIn()->post("/scans/{$scan->id}/save", ['codes' => $codes]);

        return $scan;
    }

    public function test_connecting_keeps_an_encrypted_refresh_token_and_picks_the_main_calendar(): void
    {
        $redirect = $this->signedIn()->get('/google/connect')->assertRedirect();
        parse_str(parse_url($redirect->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame('offline', $query['access_type']);
        $this->assertStringContainsString('calendar.events', $query['scope']);

        // The callback works without Amor's cookie: Google may return to a different browser view.
        $this->get('/google/callback?'.http_build_query(['state' => $query['state'], 'code' => 'abc']))
            ->assertOk()->assertSee('Google Calendar is connected')->assertSee('Anna');

        $stored = Setting::get('google');
        $this->assertNotSame('refresh', $stored['refresh_token']);
        $this->assertSame('refresh', Crypt::decryptString($stored['refresh_token']));
        $this->assertSame('anna@gmail.com', GoogleCalendar::calendarId());
        $this->assertTrue(GoogleCalendar::connected());

        // The state works once.
        $this->get('/google/callback?'.http_build_query(['state' => $query['state'], 'code' => 'abc']))->assertSee('expired');
    }

    public function test_saving_a_roster_writes_its_shifts_into_the_calendar(): void
    {
        $this->connect();
        ShiftCode::where('code', '1')->update(['start' => '06:45', 'end' => '15:15', 'title' => 'Frühdienst']);
        $this->google['anna@gmail.com/amor20261203'] = ['summary' => 'old shift on a now free day', 'extendedProperties' => ['private' => ['amor' => '1']]];
        $this->google['anna@gmail.com/dentist'] = ['summary' => 'Not from Amor', 'extendedProperties' => ['private' => []]];

        $scan = $this->savedScan();
        $this->assertSame('24 shifts saved and added to Google Calendar.', session('status'));

        $this->assertCount(25, $this->google);
        $this->assertArrayHasKey('anna@gmail.com/dentist', $this->google, 'other events stay');
        $this->assertArrayNotHasKey('anna@gmail.com/amor20261203', $this->google, 'removed: no shift that day anymore');
        $this->assertSame(1, collect($this->calls)->filter(fn ($c) => str_starts_with($c, 'DELETE'))->count(), 'only existing events are deleted');
        $this->assertSame([
            'summary' => 'Frühdienst',
            'start' => ['dateTime' => '2026-12-01T06:45:00', 'timeZone' => 'Europe/Zurich'],
            'end' => ['dateTime' => '2026-12-01T15:15:00', 'timeZone' => 'Europe/Zurich'],
        ], array_intersect_key($this->google['anna@gmail.com/amor20261201'], array_flip(['summary', 'start', 'end'])));
        $this->assertSame(['date' => '2026-12-07'], $this->google['anna@gmail.com/amor20261207']['start'], 'HO is all day');

        // Correcting the roster updates the same events instead of adding new ones.
        $codes = collect($scan->days())->pluck('code', 'date')->all();
        $codes['2026-12-01'] = '';
        $codes['2026-12-02'] = 'HO';
        $this->signedIn()->post("/scans/{$scan->id}/save", ['codes' => $codes]);
        $this->assertCount(24, $this->google);
        $this->assertArrayNotHasKey('anna@gmail.com/amor20261201', $this->google);
        $this->assertSame('HO', $this->google['anna@gmail.com/amor20261202']['summary']);

        $this->signedIn()->withSession(['saved_scan' => $scan->id])->get('/')->assertDontSee('Add them to your calendar');
    }

    public function test_rate_limits_are_waited_out(): void
    {
        $this->connect();
        $this->rateLimited = 2;

        $this->savedScan();

        $this->assertSame('24 shifts saved and added to Google Calendar.', session('status'));
        $this->assertCount(24, $this->google);
    }

    public function test_an_event_deleted_by_hand_comes_back(): void
    {
        $this->connect();
        // Google keeps the ID of a deleted event; creating it again answers 409.
        $this->google['anna@gmail.com/amor20261201'] = ['summary' => 'deleted', 'extendedProperties' => ['private' => []]];

        $this->savedScan();

        $this->assertSame('1', $this->google['anna@gmail.com/amor20261201']['summary']);
    }

    public function test_a_google_error_keeps_the_shifts_and_offers_a_retry(): void
    {
        $this->connect();
        $this->googleDown = true;

        $scan = $this->savedScan();

        $this->assertSame(24, Shift::count());
        $this->assertSame('24 shifts saved.', session('status'));
        $this->assertStringContainsString('Rate Limit Exceeded', session('google_error'));
        $this->signedIn()->withSession(['saved_scan' => $scan->id, 'google_error' => session('google_error')])->get('/')
            ->assertSee('Try again')->assertSee(route('google.sync', $scan), false);
    }

    public function test_a_revoked_connection_asks_to_connect_again(): void
    {
        Setting::put('google', ['refresh_token' => Crypt::encryptString('refresh'), 'calendar_id' => 'anna@gmail.com']);
        $this->tokenRevoked = true;

        $this->savedScan();

        $this->assertStringContainsString('Connect it again in Settings', session('google_error'));
        $this->assertFalse(GoogleCalendar::connected());
    }

    public function test_changing_the_calendar_moves_the_upcoming_shifts(): void
    {
        $this->connect();
        $this->savedScan();
        $this->assertCount(24, $this->google);

        $this->signedIn()->get('/settings')->assertSee('Arbeit')->assertSee('Disconnect Google Calendar');
        $this->signedIn()->put('/settings', [
            'person' => 'Anna Muster',
            'google_calendar' => 'work@group.calendar.google.com',
            'codes' => ShiftCode::all(['code', 'title', 'start', 'end', 'skip'])->toArray(),
        ])->assertSessionHasNoErrors();

        $this->assertSame('work@group.calendar.google.com', GoogleCalendar::calendarId());
        $this->assertCount(24, $this->google);
        $this->assertSame(24, collect(array_keys($this->google))->filter(fn ($k) => str_starts_with($k, 'work@'))->count());
    }

    public function test_the_event_color_can_be_chosen_and_reset(): void
    {
        $this->connect();
        $this->savedScan();
        $this->assertArrayNotHasKey('colorId', $this->google['anna@gmail.com/amor20261201']);

        $settings = fn (string $color) => [
            'person' => 'Anna Muster',
            'google_color' => $color,
            'codes' => ShiftCode::all(['code', 'title', 'start', 'end', 'skip'])->toArray(),
        ];
        $this->signedIn()->get('/settings')->assertSee('Basil (Basilikum)');
        $this->signedIn()->put('/settings', $settings('10'))->assertSessionHasNoErrors();
        $this->assertSame('10', GoogleCalendar::colorId());
        $this->assertSame('10', $this->google['anna@gmail.com/amor20261201']['colorId']);

        $this->signedIn()->put('/settings', $settings(''));
        $this->assertNull(GoogleCalendar::colorId());
        $this->assertArrayNotHasKey('colorId', $this->google['anna@gmail.com/amor20261201'], 'back to the calendar color');
    }

    public function test_disconnecting_revokes_the_token(): void
    {
        $this->connect();
        $this->signedIn()->post('/google/disconnect')->assertRedirect('/settings');

        $this->assertFalse(GoogleCalendar::connected());
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/revoke') && $r['token'] === 'refresh');
    }

    public function test_without_a_client_id_there_is_nothing_to_connect(): void
    {
        config(['services.google.client_id' => null]);
        $this->signedIn()->get('/google/connect')->assertNotFound();
        $this->signedIn()->get('/settings')->assertSee('set GOOGLE_CLIENT_ID', false);
    }
}
