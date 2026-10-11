<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Amor has one password (AMOR_PASSWORD). Signing in sets a cookie that lasts a year; it is
 * derived from the password, so changing the password signs out every device.
 */
class RequirePassword
{
    public const COOKIE = 'amor_signed_in';

    public function handle(Request $request, Closure $next): Response
    {
        if (! self::configured()) {
            abort(503, 'Setze AMOR_PASSWORD in .env, um Amor zu verwenden.');
        }
        if (! hash_equals(self::token(), (string) $request->cookie(self::COOKIE))) {
            return redirect()->guest(route('login'));
        }

        return $next($request);
    }

    public static function configured(): bool
    {
        return filled(config('amor.password'));
    }

    public static function token(): string
    {
        return hash_hmac('sha256', 'signed-in:'.config('amor.password'), (string) config('app.key'));
    }
}
