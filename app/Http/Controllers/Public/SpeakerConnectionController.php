<?php

namespace App\Http\Controllers\Public;

use App\Enums\ConsentType;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Speaker;
use App\Models\SpeakerConnection;
use App\Services\ConsentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * "Connect with [speaker]": the attendee, and only the attendee, chooses to
 * share their contact details with one speaker. Speakers never receive the
 * attendee list; they receive the people who asked (SpeakerReport).
 *
 * Connecting goes through the `attendee` gate (registered for this event).
 * Withdrawing lives under /account and needs only the connection to be the
 * user's own, so it keeps working after the event is over or archived.
 */
class SpeakerConnectionController extends Controller
{
    public function __construct(private ConsentService $consents) {}

    public function store(Request $request, Event $event, Speaker $speaker): RedirectResponse
    {
        $user = $request->user();

        // Staff pass the attendee gate to preview pages; they aren't leads.
        abort_if($user->isStaff(), 403, 'Staff accounts cannot connect with speakers.');
        abort_unless($event->speakers()->whereKey($speaker->id)->exists(), 404);

        if (! $event->acceptsSpeakerConnections()) {
            return back()->with('error', 'Connecting with speakers has closed for this event.');
        }

        $note = $request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null;
        $note = filled($note) ? trim($note) : null;

        if ($this->consents->isGranted($user, ConsentType::SpeakerContact, $event, $speaker)) {
            SpeakerConnection::where(['user_id' => $user->id, 'event_id' => $event->id, 'speaker_id' => $speaker->id])
                ->update(['note' => $note]);

            return back()->with('success', "Your note to {$speaker->name} is updated.");
        }

        $this->consents->grant(
            $user, ConsentType::SpeakerContact, $event,
            source: 'connect', ipAddress: $request->ip(), userAgent: substr((string) $request->userAgent(), 0, 500),
            effectiveImmediately: true, speaker: $speaker, note: $note,
        );

        return back()->with('success', "Done. {$speaker->name} will receive your details after the event.");
    }

    public function destroy(Request $request, SpeakerConnection $speakerConnection): RedirectResponse
    {
        $user = $request->user();

        // Someone else's connection is simply not found.
        abort_unless($speakerConnection->user_id === $user->id, 404);

        if ($speakerConnection->isActive() && $speakerConnection->event && $speakerConnection->speaker) {
            $this->consents->withdraw(
                $user, ConsentType::SpeakerContact, $speakerConnection->event,
                source: 'account', ipAddress: $request->ip(), userAgent: substr((string) $request->userAgent(), 0, 500),
                speaker: $speakerConnection->speaker,
            );
        }

        return back()->with('success', 'Withdrawn. '.($speakerConnection->speaker?->name ?? 'The speaker').' will no longer see your details.');
    }
}
