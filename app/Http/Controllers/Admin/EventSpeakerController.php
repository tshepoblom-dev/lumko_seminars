<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Speaker;
use App\Models\SpeakerConnection;
use App\Support\Audit;
use Illuminate\Support\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Event → Speakers: which speakers from the shared library appear at this
 * event, in what order. A session can only name speakers linked here
 * (StoreProgrammeSessionRequest), and a speaker can't be unlinked while
 * they're still on one of this event's sessions.
 */
class EventSpeakerController extends Controller
{
    public function index(Event $event): Response
    {
        $this->authorize('view', $event);

        $linked = $event->speakers()
            ->withPivot(['report_token_hash', 'report_token_expires_at', 'report_sent_at'])
            ->get();
        $connections = SpeakerConnection::where('event_id', $event->id)->active()
            ->selectRaw('speaker_id, count(*) as total')
            ->groupBy('speaker_id')
            ->pluck('total', 'speaker_id');
        $sessionsBySpeaker = DB::table('programme_session_speaker')
            ->join('programme_sessions', 'programme_sessions.id', '=', 'programme_session_speaker.programme_session_id')
            ->where('programme_sessions.event_id', $event->id)
            ->orderBy('programme_sessions.start_time')
            ->get(['programme_session_speaker.speaker_id', 'programme_sessions.title'])
            ->groupBy('speaker_id');

        return Inertia::render('Admin/Events/Speakers', [
            'speakers' => $linked->map(fn (Speaker $speaker) => [
                'id' => $speaker->id,
                'name' => $speaker->name,
                'title' => $speaker->title,
                'organisation' => $speaker->organisation,
                'photo_url' => $speaker->photo_path ? asset('storage/'.$speaker->photo_path) : null,
                'photo_approved' => $speaker->photo_approved,
                'sessions' => ($sessionsBySpeaker[$speaker->id] ?? collect())->pluck('title')->values(),
                // Speaker report: who chose to connect, and the emailed link.
                'has_contact_email' => filled($speaker->contact_email),
                'connections' => (int) ($connections[$speaker->id] ?? 0),
                'report' => $this->reportStatus($speaker),
                // Speaker portal: account, pending invitation, or neither.
                // The address is shown to staff in the invite confirmation.
                'contact_email' => $speaker->contact_email,
                'portal' => $speaker->portalSummary(),
            ]),
            'eventHasEnded' => $event->ends_at?->isPast() ?? false,
            'library' => Speaker::whereNotIn('id', $linked->pluck('id'))
                ->orderBy('name')
                ->get(['id', 'name', 'title', 'organisation']),
        ]);
    }

    /** @return array{state: string, sent_at: ?string, expires_at: ?string} */
    private function reportStatus(Speaker $speaker): array
    {
        $pivot = $speaker->pivot;
        $expiresAt = $pivot->report_token_expires_at ? Carbon::parse($pivot->report_token_expires_at) : null;

        $state = match (true) {
            $pivot->report_token_hash && $expiresAt?->isFuture() => 'active',
            (bool) $pivot->report_sent_at => 'inactive', // expired or revoked
            default => 'not_sent',
        };

        return [
            'state' => $state,
            'sent_at' => $pivot->report_sent_at ? Carbon::parse($pivot->report_sent_at)->format('j M Y') : null,
            'expires_at' => $expiresAt?->format('j M Y'),
        ];
    }

    public function store(Request $request, Event $event): RedirectResponse
    {
        $this->authorize('update', $event);

        $data = $request->validate([
            'speaker_ids' => ['required', 'array', 'min:1'],
            'speaker_ids.*' => ['integer', 'distinct', 'exists:speakers,id'],
        ]);

        $next = (int) $event->speakers()->max('event_speaker.sort_order') + 1;
        $attach = [];
        foreach ($data['speaker_ids'] as $id) {
            $attach[(int) $id] = ['sort_order' => $next++];
        }

        $changes = $event->speakers()->syncWithoutDetaching($attach);
        Audit::record('event.speakers_linked', eventId: $event->id, after: ['speaker_ids' => $changes['attached']]);

        $count = count($changes['attached']);

        return back()->with('success', $count === 1 ? 'Speaker added to this event.' : "{$count} speakers added to this event.");
    }

    /** Moves a speaker one place up or down in the event's running order. */
    public function move(Request $request, Event $event, Speaker $speaker): RedirectResponse
    {
        $this->authorize('update', $event);

        $direction = $request->validate(['direction' => ['required', 'in:up,down']])['direction'];

        DB::transaction(function () use ($event, $speaker, $direction) {
            $ids = $event->speakers()->pluck('speakers.id')->all();
            $from = array_search($speaker->id, $ids, true);
            $to = $direction === 'up' ? $from - 1 : $from + 1;

            if ($from === false || $to < 0 || $to >= count($ids)) {
                return;
            }

            [$ids[$from], $ids[$to]] = [$ids[$to], $ids[$from]];

            foreach ($ids as $position => $id) {
                $event->speakers()->updateExistingPivot($id, ['sort_order' => $position]);
            }
        });

        return back();
    }

    public function destroy(Event $event, Speaker $speaker): RedirectResponse
    {
        $this->authorize('update', $event);

        $sessions = $speaker->programmeSessions()->where('event_id', $event->id)->pluck('title');

        if ($sessions->isNotEmpty()) {
            return back()->with(
                'error',
                "{$speaker->name} is still on: ".$sessions->implode(', ').'. Remove them from those sessions first.'
            );
        }

        $event->speakers()->detach($speaker->id);
        Audit::record('event.speaker_unlinked', subject: $speaker, eventId: $event->id);

        return back()->with('success', "{$speaker->name} removed from this event. They stay in the speaker library.");
    }
}
