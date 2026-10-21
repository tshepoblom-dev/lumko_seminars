<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Cookieless visitor identity for analytics: a hash of IP + user agent with
 * a salt derived from today's date and the app key. The salt changes at
 * midnight and is never stored, so the same person is one visitor for a day
 * but cannot be linked across days, and the IP itself is never kept.
 */
class VisitorHash
{
    public static function for(Request $request): string
    {
        $salt = hash_hmac('sha256', 'visitor|'.now()->toDateString(), (string) config('app.key'));

        return hash('sha256', $salt.'|'.$request->ip().'|'.$request->userAgent());
    }

    /** Crawlers, link previews, uptime monitors and headless browsers. */
    public static function isBot(Request $request): bool
    {
        $agent = (string) $request->userAgent();

        return $agent === ''
            || (bool) preg_match('/bot|crawl|spider|slurp|preview|monitor|facebookexternalhit|whatsapp|curl|wget|python|java\/|go-http|okhttp|headless|lighthouse|phantom|puppeteer|playwright/i', $agent);
    }
}
