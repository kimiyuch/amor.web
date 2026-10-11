<?php

namespace App\Http\Controllers;

use App\Calendar\ShiftCalendar;
use App\Models\Scan;
use App\Models\Setting;
use App\Models\Shift;

class HomeController extends Controller
{
    public function __invoke()
    {
        $calendar = ShiftCalendar::fromDatabase();
        $upcoming = Shift::where('date', '>=', today())->orderBy('date')->limit(10)->get()
            ->map(fn (Shift $shift) => $calendar->eventFor($shift->date->toDateString(), $shift->code))
            ->filter()
            ->values();

        // The latest roster, if it is still being read or was read but not saved yet.
        $latest = Scan::latest('id')->first();

        return view('home', [
            'person' => Setting::person(),
            'upcoming' => $upcoming,
            // Saved shifts only reach a calendar app that subscribed to the feed.
            'needsSubscribing' => Shift::exists() && ! Setting::feedSubscribed(),
            'webcalUrl' => Setting::webcalUrl(),
            'openScan' => in_array($latest?->status, ['queued', 'reading', 'read'], true) ? $latest : null,
        ]);
    }
}
