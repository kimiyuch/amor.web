<?php

namespace App\Providers;

use App\Roster\ClaudeRosterReader;
use App\Roster\FakeRosterReader;
use App\Roster\RosterReader;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(RosterReader::class, fn () => config('amor.fake_reader')
            ? new FakeRosterReader
            : new ClaudeRosterReader((string) config('amor.anthropic_key'), (string) config('amor.model')));
    }

    public function boot(): void
    {
        //
    }
}
