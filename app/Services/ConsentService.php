<?php

namespace App\Services;

use App\Enums\ConsentType;
use App\Models\AttendeeConsent;
use App\Models\ConsentRecord;
use App\Models\Event;
use App\Models\Partner;
use App\Models\PartnerConnection;
use App\Models\Speaker;
use App\Models\SpeakerConnection;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * B7 — POPIA-defensible consent model.
 *
 * consent_records is an append-only ledger: every grant/withdraw writes a
 * new row and nothing is ever updated or deleted. attendee_consents is a
 * current-state projection kept in sync for fast reads (the account page,
 * the admin dashboard, notification guards).
 *
 * `lumko_marketing` is evaluated per user, globally (event_id null,
 * latest record wins). `networking` is per event. `follow_up_request` is
 * ledger-only (no projection row) — it is a fact staff can see, not a
 * togglable preference. `speaker_contact` is per event and per speaker
 * (the ledger row carries speaker_id) and is projected into
 * speaker_connections, which is what a speaker's report reads.
 * `partner_contact` works the same way per partner (partner_id), projected
 * into partner_connections: a partner's leads.
 */
class ConsentService
{
    public function grant(
        User $user,
        ConsentType $type,
        ?Event $event,
        string $source,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        bool $effectiveImmediately = false,
        ?Speaker $speaker = null,
        ?string $note = null,
        ?Partner $partner = null,
        ?User $capturedBy = null,
    ): ConsentRecord {
        [$purposeKey, $purposeText, $purposeVersion] = $this->wording($type, $speaker, $partner);

        $record = DB::transaction(function () use ($user, $type, $event, $source, $ipAddress, $userAgent, $effectiveImmediately, $purposeKey, $purposeText, $purposeVersion, $speaker, $note, $partner, $capturedBy) {
            $record = ConsentRecord::create([
                'user_id' => $user->id,
                'event_id' => $event?->id,
                'speaker_id' => $speaker?->id,
                'partner_id' => $partner?->id,
                'type' => $type->value,
                'action' => 'granted',
                'purpose_key' => $purposeKey,
                'purpose_text' => $purposeText,
                'purpose_version' => $purposeVersion,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'source' => $source,
                'effective_at' => $effectiveImmediately ? now() : null,
            ]);

            if ($effectiveImmediately) {
                $this->syncProjection($user, $type, $event, granted: true, speaker: $speaker, note: $note, partner: $partner, source: $source, capturedBy: $capturedBy);
            } elseif ($type === ConsentType::PartnerContact && $partner && $event && filled($note)) {
                // Not effective until the email is verified, but the
                // attendee's note must survive until then (the ledger
                // doesn't hold notes): park it on an inactive projection row.
                PartnerConnection::firstOrCreate(
                    ['user_id' => $user->id, 'event_id' => $event->id, 'partner_id' => $partner->id],
                    ['source' => $source, 'note' => $note],
                );
            }

            return $record;
        });

        return $record;
    }

    public function withdraw(
        User $user,
        ConsentType $type,
        ?Event $event,
        string $source,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        ?Speaker $speaker = null,
        ?Partner $partner = null,
    ): ConsentRecord {
        [$purposeKey, $purposeText, $purposeVersion] = $this->wording($type, $speaker, $partner);

        return DB::transaction(function () use ($user, $type, $event, $source, $ipAddress, $userAgent, $purposeKey, $purposeText, $purposeVersion, $speaker, $partner) {
            $record = ConsentRecord::create([
                'user_id' => $user->id,
                'event_id' => $event?->id,
                'speaker_id' => $speaker?->id,
                'partner_id' => $partner?->id,
                'type' => $type->value,
                'action' => 'withdrawn',
                'purpose_key' => $purposeKey,
                'purpose_text' => $purposeText,
                'purpose_version' => $purposeVersion,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'source' => $source,
                'effective_at' => now(), // withdrawal is effective immediately
            ]);

            $this->syncProjection($user, $type, $event, granted: false, speaker: $speaker, partner: $partner);

            return $record;
        });
    }

    /**
     * Marks every not-yet-effective ledger entry for this user as effective
     * (called once, after email verification) and updates the projection
     * to match. Consent given at registration has no legal weight until
     * the email is confirmed.
     */
    public function markPendingEffective(User $user): void
    {
        DB::transaction(function () use ($user) {
            $pending = ConsentRecord::where('user_id', $user->id)
                ->where('action', 'granted')
                ->whereNull('effective_at')
                ->get();

            // A pending grant that was voided (voidPending) never takes effect.
            $voided = ConsentRecord::where('user_id', $user->id)->where('action', 'voided')->get();

            foreach ($pending as $record) {
                if ($voided->contains(fn (ConsentRecord $v) => $v->id > $record->id && self::sameScope($v, $record))) {
                    continue;
                }

                $record->update(['effective_at' => now()]);

                $event = $record->event_id ? Event::find($record->event_id) : null;
                $speaker = $record->speaker_id ? Speaker::find($record->speaker_id) : null;
                $partner = $record->partner_id ? Partner::find($record->partner_id) : null;
                $this->syncProjection($user, ConsentType::from($record->type), $event, granted: true, speaker: $speaker, partner: $partner, source: $record->source);
            }
        });
    }

    /**
     * Voids every not-yet-effective grant for this user, so a later email
     * confirmation or sign-in can't switch it on: a registration made for
     * their account by someone else, then cancelled ("this wasn't me").
     * The ledger stays append-only: each pending grant gets a `voided` row
     * (never effective, so it changes no consent that already stands).
     */
    public function voidPending(User $user, string $source): int
    {
        return DB::transaction(function () use ($user, $source) {
            $pending = ConsentRecord::where('user_id', $user->id)
                ->where('action', 'granted')
                ->whereNull('effective_at')
                ->get();
            $voided = ConsentRecord::where('user_id', $user->id)->where('action', 'voided')->get();
            $count = 0;

            foreach ($pending as $record) {
                if ($voided->contains(fn (ConsentRecord $v) => $v->id > $record->id && self::sameScope($v, $record))) {
                    continue;
                }

                ConsentRecord::create([
                    ...$record->only(['user_id', 'event_id', 'speaker_id', 'partner_id', 'type', 'purpose_key', 'purpose_text', 'purpose_version']),
                    'action' => 'voided',
                    'ip_address' => request()->ip(),
                    'user_agent' => substr((string) request()->userAgent(), 0, 500),
                    'source' => $source,
                    'effective_at' => null,
                ]);

                // A partner note parked for a pending grant goes with it.
                if ($record->type === ConsentType::PartnerContact->value) {
                    PartnerConnection::where('user_id', $user->id)
                        ->where('event_id', $record->event_id)
                        ->where('partner_id', $record->partner_id)
                        ->whereNull('granted_at')
                        ->delete();
                }

                $count++;
            }

            return $count;
        });
    }

    private static function sameScope(ConsentRecord $a, ConsentRecord $b): bool
    {
        // Loose on purpose: ids come back as strings on some drivers.
        return $a->type === $b->type
            && $a->event_id == $b->event_id
            && $a->speaker_id == $b->speaker_id
            && $a->partner_id == $b->partner_id;
    }

    public function isGranted(User $user, ConsentType $type, ?Event $event = null, ?Speaker $speaker = null, ?Partner $partner = null): bool
    {
        if ($type === ConsentType::PartnerContact) {
            return $event && $partner && PartnerConnection::where('user_id', $user->id)
                ->where('event_id', $event->id)
                ->where('partner_id', $partner->id)
                ->active()
                ->exists();
        }

        if ($type === ConsentType::SpeakerContact) {
            return $event && $speaker && SpeakerConnection::where('user_id', $user->id)
                ->where('event_id', $event->id)
                ->where('speaker_id', $speaker->id)
                ->active()
                ->exists();
        }

        if ($type === ConsentType::LumkoMarketing) {
            // Global: latest effective ledger record wins, not scoped to an event.
            $latest = ConsentRecord::where('user_id', $user->id)
                ->where('type', $type->value)
                ->whereNotNull('effective_at')
                ->orderByDesc('effective_at')
                ->orderByDesc('id')
                ->first();

            return $latest && $latest->action === 'granted';
        }

        $consent = AttendeeConsent::where('user_id', $user->id)
            ->when($event, fn ($q) => $q->where('event_id', $event->id))
            ->where('type', $type->value)
            ->first();

        return (bool) $consent?->isGranted();
    }

    /**
     * attendee_consents is only used as a fast-read projection for the
     * per-event `networking` preference (the UI reads it directly on
     * /account and the networking directory). `lumko_marketing` is global
     * and always read straight from the ledger via isGranted() above, so
     * it is never projected here — that avoids needing a nullable
     * event_id on a table whose whole point is per-event state.
     * `follow_up_request` is ledger-only by design (B10) — never a
     * togglable preference, so it has no projection row either.
     */
    private function syncProjection(
        User $user,
        ConsentType $type,
        ?Event $event,
        bool $granted,
        ?Speaker $speaker = null,
        ?string $note = null,
        ?Partner $partner = null,
        ?string $source = null,
        ?User $capturedBy = null,
    ): void {
        // partner_contact: per event and per partner, projected into
        // partner_connections (a partner's Leads page). The latest grant's
        // source wins; a note is kept unless a new one replaces it.
        if ($type === ConsentType::PartnerContact) {
            if (! $event || ! $partner) {
                return;
            }

            $connection = PartnerConnection::firstOrNew([
                'user_id' => $user->id,
                'event_id' => $event->id,
                'partner_id' => $partner->id,
            ]);

            if ($granted) {
                $connection->granted_at = now();
                $connection->revoked_at = null;
                $connection->source = $source ?? $connection->source ?? 'connect';
                if (filled($note)) {
                    $connection->note = $note;
                }
                if ($capturedBy) {
                    $connection->captured_by_user_id = $capturedBy->id;
                }
            } else {
                if (! $connection->exists) {
                    return;
                }
                $connection->revoked_at = now();
            }

            $connection->save();

            return;
        }

        // speaker_contact is per event and per speaker, so it has its own
        // projection table (attendee_consents allows one row per type).
        if ($type === ConsentType::SpeakerContact) {
            if (! $event || ! $speaker) {
                return;
            }

            $connection = SpeakerConnection::firstOrNew([
                'user_id' => $user->id,
                'event_id' => $event->id,
                'speaker_id' => $speaker->id,
            ]);

            if ($granted) {
                $connection->granted_at = now();
                $connection->revoked_at = null;
                $connection->note = $note;
            } else {
                $connection->revoked_at = now();
            }

            $connection->save();

            return;
        }

        if ($type !== ConsentType::Networking || ! $event) {
            return;
        }

        $consent = AttendeeConsent::firstOrNew([
            'user_id' => $user->id,
            'event_id' => $event->id,
            'type' => $type->value,
        ]);

        if ($granted) {
            $consent->granted_at = now();
            $consent->revoked_at = null;
        } else {
            $consent->revoked_at = now();
        }

        $consent->save();
    }

    /**
     * @return array{0: string, 1: string, 2: string} [purpose_key, purpose_text, purpose_version]
     */
    public function wording(ConsentType $type, ?Speaker $speaker = null, ?Partner $partner = null): array
    {
        $all = config('seminar.consent_wording');
        $entry = $all[$type->value] ?? ['text' => $type->value, 'version' => 'v1'];
        $text = $entry['text'];

        if ($speaker) {
            $text = str_replace(':speaker', self::speakerLabel($speaker), $text);
        }

        if ($partner) {
            $text = str_replace(':partner', $partner->name, $text);
        }

        return [$type->value, $text, $entry['version']];
    }

    /** "Name (Organisation)", as the attendee sees it in the consent sentence. */
    public static function speakerLabel(Speaker $speaker): string
    {
        return $speaker->organisation ? "{$speaker->name} ({$speaker->organisation})" : $speaker->name;
    }
}
