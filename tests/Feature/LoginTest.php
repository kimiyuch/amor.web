<?php

namespace Tests\Feature;

use App\Http\Middleware\RequirePassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_pages_need_the_password(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/settings')->assertRedirect('/login');
        $this->get('/login')->assertOk()->assertSee('Password');
    }

    public function test_a_wrong_password_is_rejected(): void
    {
        $this->from('/login')->post('/login', ['password' => 'nope'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors('password');
    }

    public function test_the_right_password_signs_in_for_a_year(): void
    {
        $response = $this->post('/login', ['password' => 'secret'])->assertRedirect('/');
        $cookie = collect($response->headers->getCookies())->firstWhere(fn ($c) => $c->getName() === RequirePassword::COOKIE);
        $this->assertNotNull($cookie);
        $this->assertGreaterThan(now()->addDays(360)->timestamp, $cookie->getExpiresTime());

        $this->signedIn()->get('/')->assertOk()->assertSee('Read shifts');
    }

    public function test_changing_the_password_signs_everyone_out(): void
    {
        $this->signedIn()->get('/')->assertOk();
        $old = RequirePassword::token();
        config(['amor.password' => 'new']);
        $this->withCookie(RequirePassword::COOKIE, $old)->get('/')->assertRedirect('/login');
    }

    public function test_without_a_password_amor_stays_closed(): void
    {
        config(['amor.password' => null]);
        $this->get('/')->assertStatus(503);
        $this->post('/login', ['password' => ''])->assertStatus(503);
    }

    public function test_signing_out(): void
    {
        $response = $this->signedIn()->post('/logout')->assertRedirect('/login');
        $cookie = collect($response->headers->getCookies())->firstWhere(fn ($c) => $c->getName() === RequirePassword::COOKIE);
        $this->assertTrue($cookie->isCleared());
    }
}
