<?php

namespace App\Support;

use Illuminate\Support\Str;

class TicketCode
{
    /**
     * HMAC-SHA256 of a raw ticket code, keyed with APP_KEY. Used as the
     * lookup column so the raw (encrypted-at-rest) code is never compared
     * directly in a database query.
     */
    public static function hash(string $rawCode): string
    {
        return hash_hmac('sha256', $rawCode, config('app.key'));
    }

    public static function generate(): string
    {
        return Str::random(40);
    }
}
