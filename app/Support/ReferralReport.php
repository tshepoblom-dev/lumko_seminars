<?php

namespace App\Support;

use App\Models\ClientConversion;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Partner;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * What each partner's referrals came to: people who named the partner
 * under "How did you hear about us?", how many of them took part, and how
 * many became Lumko clients (ClientConversion, credited to the partner).
 *
 * "Took part" is a ticket checked in at the door, or for online attendees a
 * question, chat message or reaction. Watching without interacting isn't
 * recorded per person (live presence is pruned hourly), so the number is a
 * floor, and the pages say so.
 *
 * Counts only. Partners see the counts; staff also see the deal values
 * they recorded. Nobody's name leaves this class.
 */
class ReferralReport
{
    /** @return array{referred: int, verified: int, took_part: int, converted: int, deal_value_cents: int} */
    public static function forPartner(Partner $partner, ?Event $event = null): array
    {
        $registrations = EventRegistration::where('referred_by_partner_id', $partner->id)
            ->where('status', 'registered')
            ->when($event, fn ($q) => $q->where('event_id', $event->id));

        $conversions = ClientConversion::where('partner_id', $partner->id)
            ->when($event, fn ($q) => $q->where('event_id', $event->id));

        return [
            'referred' => (clone $registrations)->count(),
            'verified' => (clone $registrations)->whereHas('user', fn ($q) => $q->whereNotNull('email_verified_at'))->count(),
            'took_part' => self::tookPart(clone $registrations)->count(),
            'converted' => (clone $conversions)->count(),
            'deal_value_cents' => (int) (clone $conversions)->sum('deal_value_cents'),
        ];
    }

    /** One row per partner on the event, for the event's Partners page. */
    public static function forEvent(Event $event): array
    {
        return $event->partners()->orderBy('partners.name')->get()
            ->map(fn (Partner $p) => ['partner_id' => $p->id, 'name' => $p->name, ...self::forPartner($p, $event)])
            ->filter(fn ($row) => $row['referred'] > 0 || $row['converted'] > 0)
            ->values()
            ->all();
    }

    /** One row per partner across all events, for the partner library. */
    public static function forPartners(): array
    {
        $ids = EventRegistration::whereNotNull('referred_by_partner_id')->distinct()->pluck('referred_by_partner_id')
            ->merge(ClientConversion::whereNotNull('partner_id')->distinct()->pluck('partner_id'))
            ->unique();

        return Partner::whereIn('id', $ids)->orderBy('name')->get()
            ->map(fn (Partner $p) => ['partner_id' => $p->id, 'slug' => $p->slug, 'name' => $p->name, ...self::forPartner($p)])
            ->values()
            ->all();
    }

    /** Registrations whose attendee was checked in, or took part online. */
    private static function tookPart(Builder $registrations): Builder
    {
        return $registrations->where(function ($q) {
            $q->whereHas('ticket.checkIn')
                ->orWhereExists(fn ($s) => self::engaged($s, 'questions'))
                ->orWhereExists(fn ($s) => self::engaged($s, 'chat_messages'))
                ->orWhereExists(fn ($s) => self::engaged($s, 'reactions'));
        });
    }

    private static function engaged($query, string $table): void
    {
        $query->select(DB::raw(1))
            ->from($table)
            ->whereColumn("{$table}.user_id", 'event_registrations.user_id')
            ->whereColumn("{$table}.event_id", 'event_registrations.event_id');
    }
}
