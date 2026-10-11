<?php

namespace App\Roster;

use App\Calendar\ShiftCalendar;

/**
 * Pretends to read a roster: 34 days from the first of the current month in a fixed
 * pattern, with one uncertain cell. Used by the tests and with AMOR_FAKE_READER=true.
 */
final class FakeRosterReader implements RosterReader
{
    public const PATTERN = ['1', '1T', '', '', 'M1', 'S1/BD', 'HO', '9A', '', 'B', 'X7', '1E', '', '6A'];

    public function read(Photo $photo, string $person, callable $onStatus): array
    {
        foreach (['Ich schaue mir den Dienstplan an…', 'Ich zoome hinein…', 'Ich lese deine Zeile…'] as $status) {
            $onStatus($status);
            if (! app()->runningUnitTests()) {
                usleep(900_000);
            }
        }

        if (str_contains(strtolower($person), 'nobody')) {
            return ['person_found' => false, 'row_label' => '', 'period_start' => '', 'period_end' => '',
                'row_box' => ['x0' => 0, 'y0' => 0, 'x1' => 0, 'y1' => 0], 'days' => [],
                'notes' => 'Ähnliche Namen: Muster Anna, Keller Sara.'];
        }

        $start = now()->startOfMonth()->toDateString();
        $days = [];
        foreach (range(0, 33) as $i) {
            $days[] = ['date' => ShiftCalendar::addDays($start, $i), 'code' => self::PATTERN[$i % count(self::PATTERN)], 'uncertain' => $i === 4];
        }

        return [
            'person_found' => true,
            'row_label' => 'Muster Anna (4711)',
            'period_start' => $days[0]['date'],
            'period_end' => end($days)['date'],
            'row_box' => ['x0' => 0.03, 'y0' => 0.42, 'x1' => 0.97, 'y1' => 0.47],
            'days' => $days,
            'notes' => 'Spiegelung über dem 5., bitte diese Zelle prüfen.',
        ];
    }
}
