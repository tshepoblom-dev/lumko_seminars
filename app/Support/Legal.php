<?php

namespace App\Support;

use App\Models\LegalAcceptance;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * The current versions of the Terms & Conditions and the Privacy Policy
 * (config/legal.php), and who has accepted them.
 */
class Legal
{
    public const DOCUMENTS = ['terms', 'privacy'];

    public static function version(string $document): string
    {
        return (string) config("legal.versions.{$document}");
    }

    public static function hasAcceptedCurrent(User $user): bool
    {
        $accepted = LegalAcceptance::where('user_id', $user->id)
            ->where(function ($query) {
                foreach (self::DOCUMENTS as $document) {
                    $query->orWhere(fn ($q) => $q->where('document', $document)->where('version', self::version($document)));
                }
            })
            ->distinct()
            ->count('document');

        return $accepted === count(self::DOCUMENTS);
    }

    /**
     * Records acceptance of the current version of both documents. A
     * document already accepted at its current version isn't recorded again.
     */
    public static function recordAcceptance(User $user, string $source, ?Request $request = null): void
    {
        $request ??= request();

        foreach (self::DOCUMENTS as $document) {
            $version = self::version($document);

            $already = LegalAcceptance::where('user_id', $user->id)
                ->where('document', $document)
                ->where('version', $version)
                ->exists();

            if ($already) {
                continue;
            }

            LegalAcceptance::create([
                'user_id' => $user->id,
                'document' => $document,
                'version' => $version,
                'source' => $source,
                'ip_address' => $request?->ip(),
                'user_agent' => $request ? substr((string) $request->userAgent(), 0, 500) : null,
            ]);
        }
    }

    /** Organiser facts for the documents, with unset ones as "[TO COMPLETE]". */
    public static function facts(): array
    {
        $organiser = config('legal.organiser');
        $placeholder = config('legal.placeholder');
        $values = [...$organiser, 'refund_policy' => config('legal.refund_policy')];

        return [
            ...$values,
            'effective_date' => config('legal.effective_date'),
            'versions' => config('legal.versions'),
            'pop_retention_days' => (int) config('seminar.pop_retention_days', 0) ?: null,
            'post_event_days' => (int) config('seminar.post_event_days', 90),
            // A draft until someone marks it reviewed *and* nothing is missing.
            'is_draft' => ! config('legal.reviewed') || in_array($placeholder, $values, true),
            'placeholder' => $placeholder,
        ];
    }
}
