<?php

namespace App\Http\Controllers;

use App\Calendar\GoogleCalendar;
use App\Calendar\GoogleCalendarException;
use App\Models\Scan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class GoogleController extends Controller
{
    public function connect()
    {
        abort_unless(GoogleCalendar::configured(), 404);
        // Kept server-side rather than in the session: on iPhone, the home-screen app opens the
        // Google sign-in in a separate browser view that doesn't share its cookies.
        $state = Str::random(40);
        Cache::put("google.oauth.{$state}", true, now()->addMinutes(15));

        return redirect()->away(GoogleCalendar::authUrl($state));
    }

    /** Google sends the browser back here after the consent screen. */
    public function callback(Request $request)
    {
        abort_unless(GoogleCalendar::configured(), 404);
        if (! Cache::pull('google.oauth.'.$request->query('state', ''))) {
            return view('google-connected', ['error' => 'This sign-in link has expired. Start again in Amor\'s settings.']);
        }
        if ($request->query('error') || ! $request->query('code')) {
            return view('google-connected', ['error' => 'Google Calendar wasn\'t connected. You can try again in Amor\'s settings.']);
        }

        try {
            GoogleCalendar::connect($request->query('code'));
        } catch (GoogleCalendarException $e) {
            return view('google-connected', ['error' => $e->getMessage()]);
        }

        // Connected; now bring the shifts already saved into the calendar.
        try {
            $this->syncUpcoming();
            $warning = null;
        } catch (GoogleCalendarException $e) {
            $warning = $e->getMessage();
        }

        return view('google-connected', ['error' => null, 'warning' => $warning, 'calendar' => GoogleCalendar::calendarName()]);
    }

    public function disconnect()
    {
        GoogleCalendar::disconnect();

        return redirect()->route('settings')->with('status', 'Google Calendar is disconnected. Its events stay where they are.');
    }

    /** Tries a roster's shifts again after Google reported an error. */
    public function sync(Scan $scan)
    {
        abort_unless($scan->status === 'saved' && GoogleCalendar::connected(), 404);
        $dates = array_column($scan->days(), 'date');
        try {
            $result = GoogleCalendar::sync(min($dates), max($dates));
        } catch (GoogleCalendarException $e) {
            return redirect()->route('home')->with('google_error', $e->getMessage())->with('saved_scan', $scan->id);
        }

        return redirect()->route('home')->with('status', "{$result['saved']} ".Str::plural('shift', $result['saved']).' added to Google Calendar.');
    }

    /** Brings all shifts from today on into the calendar, e.g. right after connecting. */
    private function syncUpcoming(): void
    {
        $last = \App\Models\Shift::max('date');
        if ($last && $last >= today()->toDateString()) {
            GoogleCalendar::sync(today()->toDateString(), substr($last, 0, 10));
        }
    }
}
