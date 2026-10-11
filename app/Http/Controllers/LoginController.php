<?php

namespace App\Http\Controllers;

use App\Http\Middleware\RequirePassword;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    public function show()
    {
        return view('login');
    }

    public function store(Request $request)
    {
        abort_unless(RequirePassword::configured(), 503, 'Setze AMOR_PASSWORD in .env, um Amor zu verwenden.');
        $request->validate(['password' => 'required|string']);

        if (! hash_equals((string) config('amor.password'), $request->input('password'))) {
            throw ValidationException::withMessages(['password' => 'Das Passwort stimmt nicht.']);
        }

        Cookie::queue(RequirePassword::COOKIE, RequirePassword::token(), 60 * 24 * 365);

        return redirect()->intended(route('home'));
    }

    public function destroy()
    {
        Cookie::queue(Cookie::forget(RequirePassword::COOKIE));

        return redirect()->route('login');
    }
}
