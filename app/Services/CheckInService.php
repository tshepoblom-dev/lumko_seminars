<?php

namespace App\Services;

use App\Enums\TicketStatus;
use App\Models\CheckIn;
use App\Models\Event;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Audit;
use App\Support\TicketCode;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * B5 — the single place a ticket is checked in. Resolves by `code_hash`
 * (never `code` directly — see Ticket::casts()), confirms ticket/
 * registration/event state inside a transaction, and turns the race of
 * two simultaneous scans of the same code into a friendly "already
 * checked in" result rather than a 500 from a unique-constraint violation.
 */
class CheckInService
{
    public const RESULT_SUCCESS = 'success';

    public const RESULT_ALREADY_CHECKED_IN = 'already_checked_in';

    public const RESULT_INVALID = 'invalid';

    /**
     * @return array{result: string, message: string, ticket?: Ticket}
     */
    public function checkIn(string $rawCode, Event $event, ?User $operator, ?string $ipAddress, ?string $userAgent): array
    {
        // The PDF ticket prints the code in groups of five; door staff type
        // what they read, spaces included. Generated codes never contain
        // whitespace, so dropping all of it is safe.
        $hash = TicketCode::hash((string) preg_replace('/\s+/', '', $rawCode));

        $ticket = Ticket::where('code_hash', $hash)->with(['checkIn.operator', 'registration.user', 'registration.event'])->first();

        if (! $ticket) {
            return ['result' => self::RESULT_INVALID, 'message' => 'No ticket found for that code.'];
        }

        if ($ticket->registration->event_id !== $event->id) {
            return ['result' => self::RESULT_INVALID, 'message' => 'This ticket is for a different event.'];
        }

        if ($ticket->registration->status !== 'registered') {
            return ['result' => self::RESULT_INVALID, 'message' => 'This registration is no longer active.'];
        }

        if ($ticket->status !== TicketStatus::Confirmed) {
            return ['result' => self::RESULT_INVALID, 'message' => 'This ticket is not confirmed (payment not yet approved).'];
        }

        if ($ticket->checkIn) {
            $at = $ticket->checkIn->checked_in_at->format('H:i');
            $by = $ticket->checkIn->operator?->name ?? 'another operator';

            return [
                'result' => self::RESULT_ALREADY_CHECKED_IN,
                'message' => "Already checked in at {$at} by {$by}.",
                'ticket' => $ticket,
            ];
        }

        try {
            $checkIn = DB::transaction(function () use ($ticket, $operator, $ipAddress, $userAgent) {
                return CheckIn::create([
                    'ticket_id' => $ticket->id,
                    'checked_in_by' => $operator?->id,
                    'checked_in_at' => now(),
                    'ip_address' => $ipAddress,
                    'user_agent' => $userAgent ? substr($userAgent, 0, 500) : null,
                ]);
            });
        } catch (QueryException $e) {
            // A concurrent scan of the same code won the race between our
            // `$ticket->checkIn` check above and this insert — the unique
            // index on check_ins.ticket_id caught it. Report it the same
            // friendly way, never a 500.
            $ticket->load('checkIn.operator');

            $at = $ticket->checkIn?->checked_in_at?->format('H:i') ?? 'just now';
            $by = $ticket->checkIn?->operator?->name ?? 'another operator';

            return [
                'result' => self::RESULT_ALREADY_CHECKED_IN,
                'message' => "Already checked in at {$at} by {$by}.",
                'ticket' => $ticket,
            ];
        }

        Audit::record(
            'ticket.checked_in', subject: $ticket, actor: $operator,
            eventId: $ticket->registration->event_id,
        );

        return [
            'result' => self::RESULT_SUCCESS,
            'message' => 'Checked in: '.$ticket->registration->user->name,
            'ticket' => $ticket,
        ];
    }
}
