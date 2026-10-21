<?php

namespace App\Http\Controllers\Admin;

use App\Events\PollResultsUpdated;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Poll;
use App\Support\Audit;
use App\Support\PollLifecycle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PollController extends Controller
{
    public function index(Event $event): Response
    {
        $this->authorize('viewAny', Poll::class);
        $this->authorize('view', $event);

        return Inertia::render('Admin/Live/Polls', [
            'polls' => self::summaries($event),
        ]);
    }

    /** Every poll for the event, newest first, with exact counts. */
    public static function summaries(Event $event): array
    {
        return $event->polls()->with('options')->latest()->latest('id')->get()
            ->map(fn (Poll $p) => PollResultsUpdated::summary($p))
            ->all();
    }

    public function create(Event $event): Response
    {
        $this->authorize('create', Poll::class);
        $this->authorize('update', $event);

        return Inertia::render('Admin/Live/PollCreate');
    }

    public function store(Request $request, Event $event): RedirectResponse|JsonResponse
    {
        $this->authorize('create', Poll::class);
        $this->authorize('update', $event);

        $data = self::validatePoll($request);

        $poll = self::createPoll($event, $data, createdBy: $request->user()->id);

        Audit::record('poll.created', subject: $poll, eventId: $poll->event_id);

        // The Broadcast console creates polls without leaving the page.
        if ($request->wantsJson()) {
            return response()->json(['polls' => self::summaries($event)], 201);
        }

        return redirect()->route('admin.live.polls.index', $event)->with('success', 'Poll created as draft.');
    }

    /** Shared with the speaker stage (StageLiveController). */
    public static function validatePoll(Request $request): array
    {
        return $request->validate([
            'question' => ['required', 'string', 'max:255'],
            'options' => ['required', 'array', 'min:2', 'max:8'],
            'options.*' => ['required', 'string', 'max:255'],
            'show_results_live' => ['sometimes', 'boolean'],
        ]);
    }

    public static function createPoll(Event $event, array $data, ?int $createdBy = null, ?int $createdBySpeaker = null): Poll
    {
        return DB::transaction(function () use ($event, $data, $createdBy, $createdBySpeaker) {
            $poll = Poll::create([
                'event_id' => $event->id,
                'question' => $data['question'],
                'status' => 'draft',
                // Results show while the poll runs unless asked otherwise (the column's default).
                'show_results_live' => (bool) ($data['show_results_live'] ?? true),
                'created_by' => $createdBy,
                'created_by_speaker_id' => $createdBySpeaker,
            ]);

            foreach (array_values($data['options']) as $i => $label) {
                $poll->options()->create(['label' => $label, 'sort_order' => $i]);
            }

            return $poll;
        });
    }

    public function open(Request $request, Event $event, Poll $poll): RedirectResponse|JsonResponse
    {
        $this->authorize('update', $poll);

        PollLifecycle::open($poll);

        return $request->wantsJson()
            ? response()->json(['polls' => self::summaries($event)])
            : back()->with('success', 'Poll is now open.');
    }

    public function close(Request $request, Event $event, Poll $poll): RedirectResponse|JsonResponse
    {
        $this->authorize('update', $poll);

        PollLifecycle::close($poll);

        return $request->wantsJson()
            ? response()->json(['polls' => self::summaries($event)])
            : back()->with('success', 'Poll closed.');
    }
}
