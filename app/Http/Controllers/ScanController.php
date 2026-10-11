<?php

namespace App\Http\Controllers;

use App\Calendar\GoogleCalendar;
use App\Calendar\GoogleCalendarException;
use App\Calendar\IcsFeed;
use App\Calendar\ShiftCalendar;
use App\Jobs\ReadRoster;
use App\Models\Scan;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\ShiftCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ScanController extends Controller
{
    public function create()
    {
        return view('scans.create', ['person' => Setting::person()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'person' => 'required|string|max:100',
            'photo' => 'required|image|max:30720',
        ], [
            'person.required' => 'Gib deinen Namen ein, wie er im Dienstplan steht.',
            'photo.required' => 'Wähle ein Foto des Dienstplans.',
            'photo.image' => 'Dieses Foto lässt sich nicht öffnen. Versuch es mit einem JPEG oder PNG.',
            'photo.max' => 'Dieses Foto ist zu gross (höchstens 30 MB).',
        ]);

        Setting::put('person', trim($data['person']));
        $path = $request->file('photo')->storeAs('scans', Str::uuid().'.'.$request->file('photo')->extension(), 'local');
        $scan = Scan::create(['person' => trim($data['person']), 'photo_path' => $path, 'status_text' => 'Gleich geht es los…']);
        ReadRoster::dispatch($scan);

        return redirect()->route('scans.show', $scan);
    }

    public function show(Scan $scan)
    {
        if ($scan->isPending() || $scan->status === 'failed') {
            return view('scans.progress', ['scan' => $scan]);
        }

        $calendar = ShiftCalendar::fromDatabase();
        $days = collect($scan->days())->map(fn (array $day) => [
            ...$day,
            'kind' => $calendar->kind($day['code']),
            'event' => $calendar->eventFor($day['date'], $day['code']),
        ]);

        return view('scans.review', [
            'scan' => $scan,
            'result' => $scan->result,
            'days' => $days,
            'codes' => ShiftCode::orderBy('code')->get(['code', 'title', 'start', 'end', 'skip']),
        ]);
    }

    public function status(Scan $scan)
    {
        return response()->json($scan->only('status', 'status_text', 'error'));
    }

    public function strip(Scan $scan)
    {
        abort_unless($scan->strip_path && Storage::disk('local')->exists($scan->strip_path), 404);

        return response()->file(Storage::disk('local')->path($scan->strip_path), ['Cache-Control' => 'private, max-age=86400']);
    }

    /**
     * Saves the reviewed days. Shifts in the roster's period are replaced, so a shift that was
     * removed from the plan disappears from the calendar too.
     */
    public function save(Request $request, Scan $scan)
    {
        abort_unless(in_array($scan->status, ['read', 'saved'], true) && $scan->result['person_found'], 404);
        $dates = array_column($scan->days(), 'date');
        $codes = $request->validate(['codes' => 'array', 'codes.*' => 'nullable|string|max:20'])['codes'] ?? [];

        DB::transaction(function () use ($scan, $dates, $codes) {
            Shift::whereBetween('date', [min($dates), max($dates)])->delete();
            $known = ShiftCode::pluck('code')->map(ShiftCode::normalize(...))->all();
            foreach ($dates as $date) {
                $code = trim((string) ($codes[$date] ?? ''));
                if ($code === '') {
                    continue;
                }
                Shift::create(['date' => $date, 'code' => $code, 'scan_id' => $scan->id]);
                if (! in_array(ShiftCode::normalize($code), $known, true)) {
                    ShiftCode::create(['code' => ShiftCode::normalize($code)]);
                    $known[] = ShiftCode::normalize($code);
                }
            }
            $scan->update(['status' => 'saved']);
        });

        $shifts = ShiftCalendar::fromDatabase()->events(Shift::whereBetween('date', [min($dates), max($dates)])->get())->count();
        $saved = $shifts === 1 ? '1 Dienst gespeichert' : "{$shifts} Dienste gespeichert";

        if (GoogleCalendar::connected()) {
            try {
                GoogleCalendar::sync(min($dates), max($dates));

                return redirect()->route('home')->with('status', "{$saved} und in Google Kalender eingetragen.");
            } catch (GoogleCalendarException $e) {
                return redirect()->route('home')->with('status', "{$saved}.")
                    ->with('google_error', $e->getMessage())->with('saved_scan', $scan->id);
            }
        }

        return redirect()->route('home')->with('status', "{$saved}.")->with('saved_scan', $scan->id);
    }

    /**
     * The saved shifts of this roster as a file to import into an existing calendar
     * (on iPhone: Add All, then pick the calendar).
     */
    public function ics(Scan $scan)
    {
        abort_unless($scan->status === 'saved', 404);
        [$from, $to] = [min(array_column($scan->days(), 'date')), max(array_column($scan->days(), 'date'))];
        $shifts = Shift::whereBetween('date', [$from, $to])->orderBy('date')->get();
        $events = ShiftCalendar::fromDatabase()->events($shifts);

        return response(IcsFeed::build($events, (string) config('amor.timezone'), 'Dienste'))
            ->header('Content-Type', 'text/calendar; charset=utf-8')
            ->header('Content-Disposition', "inline; filename=\"dienste-{$from}-{$to}.ics\"");
    }
}
