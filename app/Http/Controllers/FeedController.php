<?php

namespace App\Http\Controllers;

use App\Calendar\IcsFeed;
use App\Calendar\ShiftCalendar;
use App\Models\Setting;
use App\Models\Shift;

class FeedController extends Controller
{
    public function __invoke(string $token)
    {
        abort_unless(hash_equals(Setting::feedToken(), $token), 404);
        // Remembered so Amor can stop asking to subscribe; written at most every few minutes.
        if (now()->subMinutes(10)->toIso8601String() > (string) Setting::get('feed_fetched_at', '')) {
            Setting::put('feed_fetched_at', now()->toIso8601String());
        }

        $shifts = Shift::where('date', '>=', today()->subYear())->orderBy('date')->get();
        $events = ShiftCalendar::fromDatabase()->events($shifts);

        return response(IcsFeed::build($events, (string) config('amor.timezone'), 'Dienste'))
            ->header('Content-Type', 'text/calendar; charset=utf-8')
            ->header('Content-Disposition', 'inline; filename="amor.ics"')
            ->header('Cache-Control', 'no-cache');
    }
}
