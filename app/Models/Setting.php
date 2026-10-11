<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Key-value settings that are edited in the app (the .env holds the rest).
 */
class Setting extends Model
{
    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['key', 'value'];

    protected $casts = ['value' => 'json'];

    public static function get(string $key, mixed $default = null): mixed
    {
        return static::find($key)?->value ?? $default;
    }

    public static function put(string $key, mixed $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
    }

    /** The name to look for in the roster. */
    public static function person(): string
    {
        return (string) static::get('person', '');
    }

    /** The secret part of the calendar feed URL, created on first use. */
    public static function feedToken(): string
    {
        $token = static::get('feed_token');
        if (! $token) {
            $token = Str::random(32);
            static::put('feed_token', $token);
        }

        return $token;
    }
}
