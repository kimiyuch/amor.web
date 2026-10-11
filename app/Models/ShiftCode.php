<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShiftCode extends Model
{
    protected $fillable = ['code', 'title', 'start', 'end', 'skip'];

    protected $casts = ['skip' => 'boolean'];

    /** Codes match case- and whitespace-insensitively ("s1 / bd" is "S1/BD"). */
    public static function normalize(?string $code): string
    {
        return strtoupper(preg_replace('/\s+/', '', (string) $code));
    }
}
