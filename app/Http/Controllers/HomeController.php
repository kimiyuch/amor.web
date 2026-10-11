<?php

namespace App\Http\Controllers;

use App\Calendar\GoogleCalendar;
use App\Calendar\ShiftCalendar;
use App\Models\Scan;
use App\Models\Setting;
use App\Models\Shift;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class HomeController extends Controller
{
    public function __invoke(Request $request)
    {
        // The latest roster, if it is still being read or was read but not saved yet.
        $latest = Scan::latest('id')->first();

        return view('home', [
            'person' => Setting::person(),
            'calendar' => Shift::exists() ? $this->month($request->query('month')) : null,
            'openScan' => in_array($latest?->status, ['queued', 'reading', 'read'], true) ? $latest : null,
            // Right after saving: offer the roster's shifts as a file to add to a calendar.
            'savedScan' => Scan::find(session('saved_scan')),
            'googleError' => session('google_error'),
            'google' => GoogleCalendar::connected(),
            'googleCalendar' => GoogleCalendar::connected() ? GoogleCalendar::calendarName() : null,
        ]);
    }

    /** A month as weeks from Monday to Sunday, with each day's shift. */
    private function month(mixed $value): array
    {
        $month = is_string($value) && preg_match('/^\d{4}-\d{2}$/', $value)
            ? Carbon::createFromFormat('!Y-m', $value)
            : today()->startOfMonth();
        $start = $month->copy()->startOfWeek(Carbon::MONDAY);
        $end = $month->copy()->endOfMonth()->endOfWeek(Carbon::SUNDAY);

        $calendar = ShiftCalendar::fromDatabase();
        $codes = Shift::whereBetween('date', [$start->toDateString(), $end->toDateString()])->get()
            ->mapWithKeys(fn (Shift $shift) => [$shift->date->toDateString() => $shift->code]);

        $days = collect(CarbonPeriod::create($start, $end))->map(function (Carbon $day) use ($month, $codes, $calendar) {
            $date = $day->toDateString();
            $code = $codes[$date] ?? '';

            return [
                'date' => $date,
                'day' => $day->day,
                'weekday' => $day->locale('en')->isoFormat('dd'),
                'inMonth' => $day->month === $month->month,
                'today' => $day->isToday(),
                'weekend' => $day->isWeekend(),
                'code' => $code,
                'kind' => $calendar->kind($code),
                'event' => $calendar->eventFor($date, $code),
            ];
        });

        $empty = $days->where('inMonth', true)->whereIn('kind', ['timed', 'allday'])->isEmpty();
        $next = $empty
            ? Shift::where('date', '>', $month->copy()->endOfMonth()->toDateString())->orderBy('date')->value('date')
            : null;

        return [
            'month' => $month,
            'title' => $month->locale('en')->isoFormat('MMMM YYYY'),
            'weeks' => $days->chunk(7),
            'empty' => $empty,
            'previous' => $month->copy()->subMonth()->format('Y-m'),
            'next' => $month->copy()->addMonth()->format('Y-m'),
            'isCurrent' => $month->isSameMonth(today()),
            'nextShift' => $next ? Carbon::parse($next) : null,
        ];
    }
}
