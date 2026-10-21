<?php

namespace App\Services;

use App\Models\ChatMessage;
use App\Models\CheckIn;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Poll;
use App\Models\Question;
use App\Models\Reaction;
use App\Models\Speaker;
use App\Models\SpeakerConnection;
use App\Support\Csv;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * What a speaker receives for speaking, in place of a fee:
 *
 * 1. The people who chose to connect with them (active speaker_connections
 *    only, re-read on every view, so a withdrawal takes effect at once).
 * 2. A session report with no personal data: their sessions' questions,
 *    chat and reactions, their polls, and the event's audience in
 *    aggregate. Groups smaller than MIN_GROUP are folded into "Other", so
 *    no one can be picked out from a breakdown.
 *
 * Per-session attendance and per-speaker ratings aren't recorded by the
 * platform, so the report doesn't pretend to have them.
 */
class SpeakerReport
{
    public const MIN_GROUP = 5;

    /** @return Collection<int, SpeakerConnection> */
    public function contacts(Event $event, Speaker $speaker): Collection
    {
        return SpeakerConnection::where('event_id', $event->id)
            ->where('speaker_id', $speaker->id)
            ->active()
            ->with('user:id,name,email,organisation,job_title')
            ->orderBy('granted_at')
            ->get();
    }

    /**
     * The connections as a CSV download. The caller audits it: whoever
     * downloads (the token link or the speaker portal) is recorded there.
     */
    public function contactsCsv(Event $event, Speaker $speaker): StreamedResponse
    {
        $contacts = $this->contacts($event, $speaker);
        $filename = 'connections-'.Str::slug($speaker->name).'-'.($event->slug ?: $event->id).'.csv';

        return response()->streamDownload(function () use ($contacts) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel reads accents correctly

            fputcsv($out, ['Name', 'Email', 'Organisation', 'Job title', 'Note to you', 'Connected on']);

            foreach ($contacts as $connection) {
                fputcsv($out, array_map([Csv::class, 'safe'], [
                    $connection->user?->name,
                    $connection->user?->email,
                    $connection->user?->organisation,
                    $connection->user?->job_title,
                    $connection->note,
                    $connection->granted_at?->format('Y-m-d'),
                ]));
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    public function build(Event $event, Speaker $speaker): array
    {
        return [
            'event' => [
                'name' => $event->name,
                'starts_at' => $event->starts_at?->toIso8601String(),
                'has_ended' => $event->ends_at?->isPast() ?? false,
            ],
            'speaker' => ['name' => $speaker->name, 'organisation' => $speaker->organisation],
            'contacts' => $this->contacts($event, $speaker)->map(fn (SpeakerConnection $c) => [
                'name' => $c->user?->name,
                'email' => $c->user?->email,
                'organisation' => $c->user?->organisation,
                'job_title' => $c->user?->job_title,
                'note' => $c->note,
                'since' => $c->granted_at?->toIso8601String(),
            ])->values()->all(),
            'sessions' => $this->sessions($event, $speaker),
            'polls' => $this->polls($event, $speaker),
            'audience' => $this->audience($event),
        ];
    }

    private function sessions(Event $event, Speaker $speaker): array
    {
        $sessions = $speaker->programmeSessions()
            ->where('event_id', $event->id)
            ->orderBy('start_time')
            ->get(['programme_sessions.id', 'programme_sessions.title', 'programme_sessions.start_time', 'programme_sessions.end_time']);

        $ids = $sessions->pluck('id');

        if ($ids->isEmpty()) {
            return [];
        }

        // Approved questions were already public to every attendee on the
        // Live page; they're shown without who asked them.
        $questions = Question::whereIn('programme_session_id', $ids)->approved()
            ->withCount('votes')
            ->orderByDesc('votes_count')
            ->get(['id', 'programme_session_id', 'body'])
            ->groupBy('programme_session_id');

        $submitted = Question::whereIn('programme_session_id', $ids)
            ->selectRaw('programme_session_id, count(*) as total')
            ->groupBy('programme_session_id')
            ->pluck('total', 'programme_session_id');

        $chat = ChatMessage::whereIn('programme_session_id', $ids)->whereNull('hidden_at')
            ->selectRaw('programme_session_id, count(*) as total')
            ->groupBy('programme_session_id')
            ->pluck('total', 'programme_session_id');

        $reactions = Reaction::whereIn('programme_session_id', $ids)
            ->selectRaw('programme_session_id, type, count(*) as total')
            ->groupBy('programme_session_id', 'type')
            ->toBase() // raw rows: `type` as its string value, not the ReactionType cast
            ->get()
            ->groupBy('programme_session_id');

        return $sessions->map(fn ($session) => [
            'title' => $session->title,
            'start_time' => $session->start_time?->format('H:i'),
            'end_time' => $session->end_time?->format('H:i'),
            'questions_submitted' => (int) ($submitted[$session->id] ?? 0),
            'questions' => ($questions[$session->id] ?? collect())->map(fn ($q) => [
                'body' => $q->body,
                'votes' => (int) $q->votes_count,
            ])->values()->all(),
            'chat_messages' => (int) ($chat[$session->id] ?? 0),
            'reactions' => ($reactions[$session->id] ?? collect())
                ->mapWithKeys(fn ($r) => [$r->type => (int) $r->total])
                ->all(),
        ])->values()->all();
    }

    private function polls(Event $event, Speaker $speaker): array
    {
        return Poll::where('event_id', $event->id)
            ->where('created_by_speaker_id', $speaker->id)
            ->whereIn('status', ['open', 'closed'])
            ->with(['options' => fn ($q) => $q->withCount('responses')->orderBy('sort_order')])
            ->orderBy('id')
            ->get()
            ->map(fn (Poll $poll) => [
                'question' => $poll->question,
                'responses' => (int) $poll->options->sum('responses_count'),
                'options' => $poll->options->map(fn ($o) => ['label' => $o->label, 'responses' => (int) $o->responses_count])->values()->all(),
            ])
            ->all();
    }

    /** The event's audience in aggregate; also a partner's Analytics page. */
    public function audience(Event $event): array
    {
        $registered = EventRegistration::where('event_id', $event->id)->where('status', 'registered');

        $feedback = $event->feedback();
        $feedbackCount = (clone $feedback)->count();

        return [
            'registered' => (clone $registered)->count(),
            'online' => (clone $registered)->where('attendance_mode', 'online')->count(),
            'in_person' => (clone $registered)->where('attendance_mode', 'in_person')->count(),
            'checked_in' => CheckIn::whereHas('ticket.registration', fn ($q) => $q->where('event_id', $event->id))->count(),
            'feedback_count' => $feedbackCount,
            'feedback_average' => $feedbackCount ? round((float) (clone $feedback)->avg('rating'), 1) : null,
            'industries' => $this->industryMix($event),
        ];
    }

    /** @return array<int, array{label: string, count: int}> */
    private function industryMix(Event $event): array
    {
        $counts = EventRegistration::where('event_id', $event->id)->where('status', 'registered')
            ->selectRaw('industry, count(*) as total')
            ->groupBy('industry')
            ->pluck('total', 'industry');

        $mix = [];
        $other = 0;

        foreach ($counts as $industry => $total) {
            if ($industry === '' || $industry === null || $total < self::MIN_GROUP || $industry === 'Other') {
                $other += $total;

                continue;
            }

            $mix[] = ['label' => $industry, 'count' => (int) $total];
        }

        usort($mix, fn ($a, $b) => $b['count'] <=> $a['count']);

        if ($other > 0) {
            $mix[] = ['label' => 'Other or not given', 'count' => $other];
        }

        return $mix;
    }
}
