<?php

namespace App\Http\Controllers;

use App\Models\Setting;
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
            'webcalUrl' => preg_replace('/^https?:/', 'webcal:', $feed),
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

        return redirect()->route('settings')->with('status', 'Settings saved.');
    }

    /** A new feed link; calendars subscribed to the old one stop updating. */
    public function resetFeed()
    {
        Setting::put('feed_token', Str::random(32));

        return redirect()->route('settings')->with('status', 'New calendar link created. Subscribe again with it.');
    }
}
