<?php

namespace Tests;

use App\Http\Middleware\RequirePassword;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['amor.password' => 'secret', 'amor.fake_reader' => true, 'amor.timezone' => 'Europe/Zurich']);
    }

    /** Requests with the cookie a correct password sets. */
    protected function signedIn(): static
    {
        return $this->withCookie(RequirePassword::COOKIE, RequirePassword::token());
    }
}
