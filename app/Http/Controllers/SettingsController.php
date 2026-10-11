<?php

namespace App\Http\Controllers;

use App\Calendar\GoogleCalendar;
use App\Calendar\GoogleCalendarException;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\ShiftCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SettingsController extends Controller
{
    public function edit()
    {
        $feed = route('feed', Setting::feedToken());

        return view('settings', [
            'person' => Setting::person(),
            'codes' => ShiftCode::orderBy('code')->get(),
            'feedUrl' => $feed,
            'webcalUrl' => Setting::webcalUrl(),
            'subscribed' => Setting::feedSubscribed(),
            'googleConfigured' => GoogleCalendar::configured(),
            'googleConnected' => GoogleCalendar::connected(),
            'googleCalendarId' => GoogleCalendar::calendarId(),
            'googleCalendars' => $this->googleCalendars(),
            'googleColor' => GoogleCalendar::colorId(),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'person' => 'nullable|string|max:100',
            'codes' => 'array',
            'codes.*.code' => 'nullable|string|max:20',
            'codes.*.title' => 'nullable|string|max:100',
            'codes.*.start' => ['nullable', 'regex:/^\d{2}:\d{2}$/'],
            'codes.*.end' => ['nullable', 'regex:/^\d{2}:\d{2}$/'],
            'codes.*.skip' => 'nullable|boolean',
            'google_calendar' => 'nullable|string|max:300',
            'google_color' => 'nullable|string|max:2',
        ]);

        Setting::put('person', trim((string) ($data['person'] ?? '')));
        DB::transaction(function () use ($data) {
            ShiftCode::query()->delete();
            $seen = [];
            foreach ($data['codes'] ?? [] as $row) {
                $code = ShiftCode::normalize($row['code'] ?? '');
                if ($code === '' || isset($seen[$code])) {
                    continue;
                }
                $seen[$code] = true;
                ShiftCode::create([
                    'code' => $code,
                    'title' => trim((string) ($row['title'] ?? '')) ?: null,
                    'start' => $row['start'] ?? null,
                    'end' => $row['end'] ?? null,
                    'skip' => (bool) ($row['skip'] ?? false),
                ]);
            }
        });

        // Shift times or the calendar may have changed: bring the upcoming shifts up to date.
        if (GoogleCalendar::connected()) {
            try {
                GoogleCalendar::useColor($data['google_color'] ?? null);
                $this->updateGoogle($data['google_calendar'] ?? null);
            } catch (GoogleCalendarException $e) {
                return redirect()->route('settings')->with('status', 'Einstellungen gespeichert.')->withErrors(['google' => $e->getMessage()]);
            }
        }

        return redirect()->route('settings')->with('status', 'Einstellungen gespeichert.');
    }

    private function updateGoogle(?string $calendarId): void
    {
        $from = today()->toDateString();
        $to = substr((string) Shift::max('date'), 0, 10);
        $previous = GoogleCalendar::calendarId();

        if ($calendarId && $calendarId !== $previous) {
            $calendar = collect(GoogleCalendar::calendars())->firstWhere('id', $calendarId);
            abort_unless($calendar, 422);
            if ($to >= $from) {
                GoogleCalendar::clear($previous, $from, $to);
            }
            GoogleCalendar::useCalendar($calendar['id'], $calendar['name']);
        }
        if ($to >= $from) {
            GoogleCalendar::sync($from, $to);
        }
    }

    /** @return list<array{id: string, name: string, primary: bool}> */
    private function googleCalendars(): array
    {
        if (! GoogleCalendar::connected()) {
            return [];
        }
        try {
            return GoogleCalendar::calendars();
        } catch (GoogleCalendarException) {
            return [];
        }
    }

    /** A new feed link; calendars subscribed to the old one stop updating. */
    public function resetFeed()
    {
        Setting::put('feed_token', Str::random(32));
        Setting::put('feed_fetched_at', null);

        return redirect()->route('settings')->with('status', 'Neuer Kalender-Link erstellt. Abonniere den Kalender damit neu.');
    }
}
