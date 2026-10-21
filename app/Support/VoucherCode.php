<?php

namespace App\Support;

/**
 * Invitation (voucher) codes: 12 characters of Crockford base32, shown as
 * XXXX-XXXX-XXXX — about 60 bits, so guessing a live one is hopeless even
 * at the registration throttles. The alphabet leaves out I, L, O and U, and
 * normalise() maps the look-alikes back (O→0, I/L→1), so a code read off a
 * printed invitation or typed in lower case still matches.
 */
class VoucherCode
{
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    private const LENGTH = 12;

    public static function generate(): string
    {
        $raw = '';
        for ($i = 0; $i < self::LENGTH; $i++) {
            $raw .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return self::format($raw);
    }

    public static function normalise(string $code): string
    {
        $code = preg_replace('/[^A-Z0-9]/', '', strtoupper($code)) ?? '';

        return strtr($code, ['O' => '0', 'I' => '1', 'L' => '1']);
    }

    /** XXXX-XXXX-XXXX, from any spelling of the code. */
    public static function format(string $code): string
    {
        return implode('-', str_split(self::normalise($code), 4));
    }

    /**
     * HMAC-SHA256 of the normalised code, keyed with APP_KEY — the lookup
     * column, as TicketCode::hash() is for tickets.
     */
    public static function hash(string $code): string
    {
        return hash_hmac('sha256', self::normalise($code), config('app.key'));
    }
}
