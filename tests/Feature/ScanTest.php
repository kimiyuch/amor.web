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
            ->assertSee('Auf dem Foto prüfen')
            ->assertSee('Dienste speichern');
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
        $this->signedIn()->get("/scans/{$scan->id}")->assertOk()->assertSee('steht nicht in diesem Dienstplan')->assertSee('Keller Sara');
        $this->signedIn()->post("/scans/{$scan->id}/save")->assertNotFound();
        $this->signedIn()->get("/scans/{$scan->id}/shifts.ics")->assertNotFound();
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
            ->assertSessionHas('status', '24 Dienste gespeichert.');

        // Right after saving, the home page offers the shifts as a file to add to a calendar.
        $this->signedIn()->withSession(['saved_scan' => $scan->id])->get('/')
            ->assertSee('Füge sie deinem Kalender hinzu')
            ->assertSee(route('scans.ics', $scan), false);
        $ics = $this->signedIn()->get("/scans/{$scan->id}/shifts.ics")->assertOk();
        $this->assertStringStartsWith('text/calendar', $ics->headers->get('Content-Type'));
        $this->assertStringContainsString('filename="dienste-2026-12-01-2027-01-03.ics"', $ics->headers->get('Content-Disposition'));
        $this->assertSame(24, substr_count($ics->getContent(), 'BEGIN:VEVENT'));
        $this->assertStringNotContainsString('DTSTART;VALUE=DATE:20261130', $ics->getContent(), 'only this roster\'s period');

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

    public function test_the_home_page_shows_the_month_with_its_shifts(): void
    {
        ShiftCode::where('code', '1')->update(['start' => '06:45', 'end' => '15:15', 'title' => 'Frühdienst']);
        ShiftCode::where('code', 'B')->update(['skip' => true]);
        Shift::create(['date' => '2026-12-02', 'code' => '1']);
        Shift::create(['date' => '2026-12-05', 'code' => 'B']);
        Shift::create(['date' => '2027-02-10', 'code' => 'HO']);

        $this->signedIn()->get('/')
            ->assertSee('Dezember 2026')
            ->assertSee('Mittwoch, 2. Dezember, Frühdienst, 06:45 – 15:15')   // for screen readers
            ->assertSee('Samstag, 5. Dezember, B')                             // skipped
            ->assertDontSee('Februar 2027')
            ->assertSee('?month=2027-01', false)
            ->assertSee('Dienstplan hinzufügen')
            ->assertSee(route('scans.create'), false)
            ->assertSee('Hier ist dein Monat.')
            ->assertDontSee('Foto des Dienstplans');

        $this->signedIn()->get('/?month=2027-01')
            ->assertSee('Januar 2027')
            ->assertSee('Keine Dienste im Januar.')
            ->assertSee('Nächster Dienst: Mi 10.02.')
            ->assertSee('Heute');

        $this->signedIn()->get('/?month=nonsense')->assertSee('Dezember 2026');
    }

    public function test_without_shifts_the_home_page_introduces_amor(): void
    {
        $this->signedIn()->get('/')
            ->assertSee('Jeder Dienst direkt in deinem Kalender')
            ->assertSee('Ersten Dienstplan hinzufügen');
    }

    public function test_the_add_a_roster_page(): void
    {
        Setting::put('person', 'Anna Muster');
        $this->signedIn()->get('/scans/new')
            ->assertOk()
            ->assertSee('Dienstplan hinzufügen')
            ->assertSee('value="Anna Muster"', false)
            ->assertSee('So liest Amor das Foto am besten')
            ->assertSee('Dienste lesen');
    }
}
