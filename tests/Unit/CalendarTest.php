<?php

namespace Tests\Unit;

use App\Calendar\IcsFeed;
use App\Calendar\ShiftCalendar;
use App\Models\ShiftCode;
use PHPUnit\Framework\TestCase;

class CalendarTest extends TestCase
{
    private function calendar(): ShiftCalendar
    {
        return new ShiftCalendar([
            new ShiftCode(['code' => '1', 'title' => 'Frühdienst', 'start' => '06:45', 'end' => '15:15', 'skip' => false]),
            new ShiftCode(['code' => 'S1/BD', 'title' => '', 'start' => '20:00', 'end' => '07:30', 'skip' => false]),
            new ShiftCode(['code' => 'HO', 'title' => 'Home Office', 'skip' => false]),
            new ShiftCode(['code' => 'B', 'skip' => true]),
        ]);
    }

    public function test_codes_match_case_and_whitespace_insensitively(): void
    {
        $this->assertSame('S1/BD', ShiftCode::normalize(' s1 / bd '));
        $this->assertSame('S1/BD', $this->calendar()->find('s1/ BD')->code);
        $this->assertNull($this->calendar()->find('X'));
    }

    public function test_timed_overnight_all_day_and_skipped_codes(): void
    {
        $calendar = $this->calendar();

        $timed = $calendar->eventFor('2026-12-01', '1');
        $this->assertSame([false, '2026-12-01T06:45', '2026-12-01T15:15', 'Frühdienst', '06:45 – 15:15'],
            [$timed->allDay, $timed->start, $timed->end, $timed->title, $timed->when()]);

        $night = $calendar->eventFor('2026-12-31', 's1/bd');
        $this->assertSame('2027-01-01T07:30', $night->end);
        $this->assertSame('S1/BD', $night->title, 'without a title of its own, the event is called by its code');
        $this->assertFalse($night->named);
        $this->assertTrue($timed->named);
        $this->assertSame('20:00 – 07:30 next day', $night->when());

        $allDay = $calendar->eventFor('2026-12-02', 'HO');
        $this->assertSame([true, '2026-12-02', '2026-12-03', 'All day'], [$allDay->allDay, $allDay->start, $allDay->end, $allDay->when()]);

        $this->assertSame('X7', $calendar->eventFor('2026-12-03', 'x7')->title);
        $this->assertNull($calendar->eventFor('2026-12-04', 'B'));
        $this->assertNull($calendar->eventFor('2026-12-04', ' '));

        $this->assertSame(['timed', 'allday', 'skipped', 'free'], array_map($calendar->kind(...), ['1', 'X7', 'b', '']));
    }

    public function test_add_days_crosses_month_and_year_boundaries(): void
    {
        $this->assertSame('2027-01-01', ShiftCalendar::addDays('2026-12-31', 1));
        $this->assertSame('2028-02-29', ShiftCalendar::addDays('2028-02-28', 1));
    }

    public function test_ics_lines_are_folded_at_75_octets(): void
    {
        $long = new ShiftCode(['code' => 'L', 'title' => str_repeat('Nachtdienst Anästhesie ', 6), 'skip' => false]);
        $ics = IcsFeed::build((new ShiftCalendar([$long]))->events([['date' => '2026-12-01', 'code' => 'L']]), 'Europe/Zurich');
        foreach (explode("\r\n", $ics) as $line) {
            $this->assertLessThanOrEqual(75, strlen($line), $line);
        }
        $this->assertStringContainsString("\r\n ", $ics);
    }

    public function test_ics_outside_central_europe_uses_utc(): void
    {
        $ics = IcsFeed::build($this->calendar()->events([['date' => '2026-07-01', 'code' => '1']]), 'America/New_York');
        $this->assertStringNotContainsString('VTIMEZONE', $ics);
        $this->assertStringContainsString('DTSTART:20260701T104500Z', $ics); // 06:45 EDT
    }
}
