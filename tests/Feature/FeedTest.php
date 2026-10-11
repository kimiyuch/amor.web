<?php

namespace Tests\Feature;

use App\Calendar\ShiftEvent;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\ShiftCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_feed_needs_its_secret(): void
    {
        $this->get('/feed/wrong.ics')->assertNotFound();
    }

    public function test_the_feed_lists_the_shifts_without_signing_in(): void
    {
        $this->travelTo('2026-12-01');
        ShiftCode::where('code', '1')->update(['start' => '06:45', 'end' => '15:15']);
        ShiftCode::where('code', 'S1/BD')->update(['start' => '20:00', 'end' => '07:30']);
        ShiftCode::where('code', 'B')->update(['skip' => true]);
        foreach (['2026-12-01' => '1', '2026-12-31' => 'S1/BD', '2026-12-02' => 'HO', '2026-12-03' => 'B'] as $date => $code) {
            Shift::create(['date' => $date, 'code' => $code]);
        }

        $response = $this->get('/feed/'.Setting::feedToken().'.ics')->assertOk();
        $ics = $response->getContent();

        $this->assertStringStartsWith('text/calendar', $response->headers->get('Content-Type'));
        $this->assertStringContainsString("X-WR-CALNAME:Dienste\r\n", $ics);
        $this->assertStringContainsString("DTSTART;TZID=Europe/Zurich:20261201T064500\r\nDTEND;TZID=Europe/Zurich:20261201T151500", $ics);
        $this->assertStringContainsString('DTEND;TZID=Europe/Zurich:20270101T073000', $ics);
        $this->assertStringContainsString("DTSTART;VALUE=DATE:20261202\r\nDTEND;VALUE=DATE:20261203", $ics);
        $this->assertStringContainsString('UID:amor-20261201@amor.kimiyu.ch', $ics);
        $this->assertSame(3, substr_count($ics, 'BEGIN:VEVENT'), 'the skipped code makes no event');
    }

    public function test_event_ids_stay_the_same_per_date(): void
    {
        $a = new ShiftEvent('2026-12-01', '1', '1', true, '2026-12-01', '2026-12-02');
        $b = new ShiftEvent('2026-12-01', 'HO', 'HO', true, '2026-12-01', '2026-12-02');
        $this->assertSame($a->uid(), $b->uid());
    }
}
