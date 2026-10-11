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

        $shifts = Shift::where('date', '>=', today()->subYear())->orderBy('date')->get();
        $events = ShiftCalendar::fromDatabase()->events($shifts);

        return response(IcsFeed::build($events, (string) config('amor.timezone'), 'Dienste'))
            ->header('Content-Type', 'text/calendar; charset=utf-8')
            ->header('Content-Disposition', 'inline; filename="amor.ics"')
            ->header('Cache-Control', 'no-cache');
    }
}
