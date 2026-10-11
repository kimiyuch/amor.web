<?php

namespace Tests\Feature;

use App\Models\Scan;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\ShiftCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ScanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->travelTo('2026-12-01 09:00');
    }

    private function scan(string $person = 'Anna Muster'): Scan
    {
        $this->signedIn()->post('/scans', [
            'person' => $person,
            'photo' => UploadedFile::fake()->image('roster.jpg', 1600, 1000),
        ])->assertRedirect();

        return Scan::latest('id')->first();
    }

    public function test_a_photo_is_read_and_then_deleted(): void
    {
        $scan = $this->scan();

        $this->assertSame('read', $scan->status);
        $this->assertSame('Anna Muster', Setting::person());
        $this->assertNull($scan->photo_path);
        $this->assertCount(1, Storage::disk('local')->allFiles(), 'only the row strip is kept');
        Storage::disk('local')->assertExists($scan->strip_path);
        $this->assertCount(34, $scan->days());

        $this->signedIn()->get("/scans/{$scan->id}")
            ->assertOk()
            ->assertSee('Muster Anna (4711)')
            ->assertSee('01.12.2026 – 03.01.2027')
            ->assertSee('Check on the photo')
            ->assertSee('Add to calendar');
        $this->signedIn()->get("/scans/{$scan->id}/row.jpg")->assertOk();
        $this->signedIn()->withCredentials()->getJson("/scans/{$scan->id}/status")->assertJson(['status' => 'read']);
    }

    public function test_a_photo_is_required(): void
    {
        $this->signedIn()->post('/scans', ['person' => 'Anna Muster'])->assertSessionHasErrors('photo');
    }

    public function test_a_person_who_is_not_in_the_roster(): void
    {
        $scan = $this->scan('Nobody Here');
        $this->signedIn()->get("/scans/{$scan->id}")->assertOk()->assertSee('isn\'t in this roster', false)->assertSee('Keller Sara');
        $this->signedIn()->post("/scans/{$scan->id}/save")->assertNotFound();
    }

    public function test_saving_replaces_the_shifts_of_the_period(): void
    {
        $scan = $this->scan();
        Shift::create(['date' => '2026-12-03', 'code' => 'OLD']);   // inside the period: replaced
        Shift::create(['date' => '2026-11-30', 'code' => '1']);     // before it: kept
        $codes = collect($scan->days())->pluck('code', 'date')->all();
        $codes['2026-12-02'] = 'HO';

        $this->signedIn()->post("/scans/{$scan->id}/save", ['codes' => $codes])
            ->assertRedirect('/')
            ->assertSessionHas('status');

        $this->assertSame('saved', $scan->fresh()->status);
        $this->assertSame('HO', Shift::whereDate('date', '2026-12-02')->value('code'));
        $this->assertFalse(Shift::whereDate('date', '2026-12-03')->exists());
        $this->assertTrue(Shift::whereDate('date', '2026-11-30')->exists());
        $this->assertTrue(ShiftCode::where('code', 'X7')->exists(), 'codes new to the table are added');

        // Correcting the roster later removes a shift that is gone from it.
        $codes['2026-12-01'] = '';
        $this->signedIn()->post("/scans/{$scan->id}/save", ['codes' => $codes]);
        $this->assertFalse(Shift::whereDate('date', '2026-12-01')->exists());
    }

    public function test_the_home_page_lists_upcoming_shifts(): void
    {
        ShiftCode::where('code', '1')->update(['start' => '06:45', 'end' => '15:15']);
        Shift::create(['date' => '2026-12-02', 'code' => '1']);
        Shift::create(['date' => '2026-11-20', 'code' => 'HO']);

        $this->signedIn()->get('/')
            ->assertSee('Coming up')
            ->assertSee('06:45 – 15:15')
            ->assertDontSee('Dienst HO');
    }
}
