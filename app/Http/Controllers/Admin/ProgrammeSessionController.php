<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreProgrammeSessionRequest;
use App\Http\Requests\Admin\UpdateProgrammeSessionRequest;
use App\Models\Event;
use App\Models\ProgrammeSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * An event's programme. Sessions name their speakers from the speakers
 * already linked to the event (Event → Speakers), never straight from
 * the shared library.
 */
class ProgrammeSessionController extends Controller
{
    public function index(Event $event): Response
    {
        $this->authorize('viewAny', ProgrammeSession::class);
        $this->authorize('view', $event);

        return Inertia::render('Admin/ProgrammeSessions/Index', [
            'sessions' => $event->programmeSessions()
                ->with('speakers')
                ->orderBy('start_time')
                ->get()
                ->map(fn (ProgrammeSession $session) => [
                    'id' => $session->id,
                    'title' => $session->title,
                    'start_time' => $session->start_time,
                    'end_time' => $session->end_time,
                    'sort_order' => $session->sort_order,
                    'speakers' => $session->speakers->pluck('name'),
                ]),
        ]);
    }

    public function create(Event $event): Response
    {
        $this->authorize('create', ProgrammeSession::class);
        $this->authorize('update', $event);

        return Inertia::render('Admin/ProgrammeSessions/Create', [
            'speakers' => $this->eventSpeakers($event),
            'nextSortOrder' => (int) $event->programmeSessions()->max('sort_order') + 1,
        ]);
    }

    public function store(StoreProgrammeSessionRequest $request, Event $event): RedirectResponse
    {
        DB::transaction(function () use ($request, $event) {
            $session = $event->programmeSessions()->create($request->safe()->except('speaker_ids'));
            $this->syncSpeakers($session, $request->validated('speaker_ids', []));
        });

        return redirect()->route('admin.programme-sessions.index', $event)->with('success', 'Session created.');
    }

    public function edit(Event $event, ProgrammeSession $programmeSession): Response
    {
        $this->authorize('update', $programmeSession);

        return Inertia::render('Admin/ProgrammeSessions/Edit', [
            'session' => [
                ...$programmeSession->only(['id', 'title', 'description', 'start_time', 'end_time', 'sort_order']),
                'speaker_ids' => $programmeSession->speakers()->pluck('speakers.id'),
            ],
            'speakers' => $this->eventSpeakers($event),
        ]);
    }

    public function update(UpdateProgrammeSessionRequest $request, Event $event, ProgrammeSession $programmeSession): RedirectResponse
    {
        DB::transaction(function () use ($request, $programmeSession) {
            $programmeSession->update($request->safe()->except('speaker_ids'));
            $this->syncSpeakers($programmeSession, $request->validated('speaker_ids', []));
        });

        return redirect()->route('admin.programme-sessions.index', $event)->with('success', 'Session updated.');
    }

    public function destroy(Event $event, ProgrammeSession $programmeSession): RedirectResponse
    {
        $this->authorize('delete', $programmeSession);

        $programmeSession->delete();

        return redirect()->route('admin.programme-sessions.index', $event)->with('success', 'Session deleted.');
    }

    private function eventSpeakers(Event $event)
    {
        return $event->speakers()->get(['speakers.id', 'speakers.name', 'speakers.organisation'])
            ->map(fn ($speaker) => $speaker->only(['id', 'name', 'organisation']));
    }

    /** Keeps the order the speakers were ticked in. */
    private function syncSpeakers(ProgrammeSession $session, array $speakerIds): void
    {
        $session->speakers()->sync(
            collect($speakerIds)->values()->mapWithKeys(fn ($id, $i) => [(int) $id => ['sort_order' => $i]])->all()
        );
    }
}
