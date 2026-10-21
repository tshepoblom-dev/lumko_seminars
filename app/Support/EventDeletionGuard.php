<?php

namespace App\Support;

use App\Models\Event;
use Illuminate\Support\Facades\DB;

/**
 * B12/D5 — events with any related data cannot be deleted. This is the
 * friendly, application-level half: a 422 with a per-relation count,
 * checked *before* the database's own restrictOnDelete() FKs would
 * otherwise throw a raw QueryException. Archive is the alternative.
 */
class EventDeletionGuard
{
    /**
     * @return array<string, int> non-zero related-row counts, empty if
     *                            the event has no related data at all (safe to hard-delete).
     */
    public function blockingCounts(Event $event): array
    {
        $registrationIds = DB::table('event_registrations')->where('event_id', $event->id)->pluck('id');

        $counts = [
            'registrations' => $registrationIds->count(),
            'payments' => DB::table('payments')->whereIn('event_registration_id', $registrationIds)->count(),
            'tickets' => DB::table('tickets')->whereIn('event_registration_id', $registrationIds)->count(),
            'check_ins' => DB::table('check_ins')
                ->whereIn('ticket_id', DB::table('tickets')->whereIn('event_registration_id', $registrationIds)->pluck('id'))
                ->count(),
            'attendee_consents' => DB::table('attendee_consents')->where('event_id', $event->id)->count(),
            'consent_records' => DB::table('consent_records')->where('event_id', $event->id)->count(),
            'announcements' => DB::table('announcements')->where('event_id', $event->id)->count(),
            'resources' => DB::table('resources')->where('event_id', $event->id)->count(),
            'questions' => DB::table('questions')->where('event_id', $event->id)->count(),
            'polls' => DB::table('polls')->where('event_id', $event->id)->count(),
            'reactions' => DB::table('reactions')->where('event_id', $event->id)->count(),
            'feedback' => DB::table('feedback')->where('event_id', $event->id)->count(),
            'connections' => DB::table('connections')->where('event_id', $event->id)->count(),
            'programme_sessions' => DB::table('programme_sessions')->where('event_id', $event->id)->count(),
        ];

        return array_filter($counts, fn ($count) => $count > 0);
    }

    public function canDelete(Event $event): bool
    {
        return $this->blockingCounts($event) === [];
    }
}
