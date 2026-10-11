<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\ShiftCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_page_shows_the_feed_and_the_codes(): void
    {
        $token = Setting::feedToken();
        $this->signedIn()->get('/settings')
            ->assertOk()
            ->assertSee(preg_replace('/^https?:/', 'webcal:', route('feed', $token)), false)
            ->assertSee('S1/BD');
    }

    public function test_saving_the_name_and_codes(): void
    {
        $this->signedIn()->put('/settings', [
            'person' => ' Anna Muster ',
            'codes' => [
                ['code' => ' 1 ', 'title' => 'Frühdienst', 'start' => '06:45', 'end' => '15:15', 'skip' => '0'],
                ['code' => 's1/bd', 'title' => '', 'start' => '20:00', 'end' => '07:30', 'skip' => '0'],
                ['code' => 'S1 / BD', 'title' => 'duplicate', 'skip' => '0'],
                ['code' => 'b', 'skip' => '1'],
                ['code' => '', 'title' => 'empty row'],
            ],
        ])->assertRedirect('/settings');

        $this->assertSame('Anna Muster', Setting::person());
        $this->assertSame(['1', 'B', 'S1/BD'], ShiftCode::orderBy('code')->pluck('code')->all());
        $this->assertTrue(ShiftCode::where('code', 'B')->value('skip'));
        $this->assertSame('Frühdienst', ShiftCode::where('code', '1')->value('title'));
    }

    public function test_times_must_be_times(): void
    {
        $this->signedIn()->put('/settings', ['codes' => [['code' => '1', 'start' => 'early']]])
            ->assertSessionHasErrors('codes.0.start');
    }

    public function test_a_new_feed_link(): void
    {
        $old = Setting::feedToken();
        $this->signedIn()->post('/settings/feed')->assertRedirect('/settings');
        $this->assertNotSame($old, Setting::feedToken());
        $this->get("/feed/{$old}.ics")->assertNotFound();
    }
}
