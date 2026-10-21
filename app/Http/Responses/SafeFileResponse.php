<?php

namespace App\Http\Responses;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * B3 — the single place every private file (resource download, PoP view)
 * is served from. Never a public URL, never a raw `Storage::response()`
 * call scattered per-controller.
 *
 * - Missing file -> 404 (never a 500 from a missing-file exception).
 * - `attachment` disposition by default; `allowInline` only lets image/PDF
 *   mimes render inline (for staff viewing a PoP), everything else still
 *   forces a download even if the caller asked for inline.
 * - Always sets nosniff, a sandboxed CSP and no-store caching — this is
 *   private, sensitive content, never something a CDN/browser should cache
 *   or try to execute.
 */
class SafeFileResponse
{
    private const INLINE_ALLOWED_MIMES = ['image/png', 'image/jpeg', 'application/pdf'];

    public static function make(string $disk, ?string $path, string $displayName, bool $allowInline = false): StreamedResponse
    {
        $storage = Storage::disk($disk);

        abort_if($path === null || $path === '', 404);
        abort_unless($storage->exists($path), 404);

        $mime = $storage->mimeType($path) ?: 'application/octet-stream';
        $inline = $allowInline && in_array($mime, self::INLINE_ALLOWED_MIMES, true);

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "sandbox; default-src 'none'",
            'Cache-Control' => 'private, no-store',
        ];

        return $storage->response($path, $displayName, $headers, $inline ? 'inline' : 'attachment');
    }
}
