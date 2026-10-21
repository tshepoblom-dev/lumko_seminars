<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreSpeakerRequest;
use App\Http\Requests\Admin\UpdateSpeakerRequest;
use App\Models\Event;
use App\Models\Speaker;
use App\Services\PortalInvitationService;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class SpeakerController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', Speaker::class);

        $visibleEventIds = Event::visibleTo(Auth::user())->pluck('id');

        return Inertia::render('Admin/Speakers/Index', [
            'speakers' => Speaker::orderBy('name')
                ->with(['events' => fn ($q) => $q->whereIn('events.id', $visibleEventIds)->orderByDesc('starts_at')])
                ->get()
                ->map(fn (Speaker $speaker) => [
                    ...$speaker->only(['id', 'name', 'title', 'organisation', 'photo_path', 'photo_approved']),
                    'events' => $speaker->events->map(fn (Event $e) => ['id' => $e->id, 'name' => $e->name]),
                    'portal_status' => $speaker->portalStatus(),
                    'has_contact_email' => filled($speaker->contact_email),
                    'contact_email' => $speaker->contact_email,
                    'portal' => $speaker->portalSummary(),
                ]),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', Speaker::class);

        // Opened from an event's Speakers page: the new speaker is linked
        // straight back to that event.
        $event = $request->filled('event')
            ? Event::visibleTo(Auth::user())->find($request->integer('event'))
            : null;

        return Inertia::render('Admin/Speakers/Create', [
            'forEvent' => $event ? ['id' => $event->id, 'name' => $event->name] : null,
        ]);
    }

    public function store(StoreSpeakerRequest $request): RedirectResponse
    {
        $data = $request->safe()->only(['user_id', 'name', 'title', 'organisation', 'contact_email', 'bio']);

        if ($request->hasFile('photo')) {
            $data['photo_path'] = $request->file('photo')->store('speakers', 'public');
            $data['photo_alt'] = $request->validated('photo_alt');
            // D-Programme data — a freshly uploaded photo always starts
            // unapproved; approval is a separate, deliberate action.
            $data['photo_approved'] = false;
        }

        $speaker = Speaker::create($data);

        // "Email them an invitation to the speaker portal" (ticked by default
        // once a contact email is given). A failed email doesn't undo the
        // speaker; the message says so.
        [$flash, $inviteNote] = ['success', ''];
        if ($request->boolean('invite_to_portal') && filled($speaker->contact_email)) {
            try {
                app(PortalInvitationService::class)->inviteSpeaker($speaker, $request->user());
                $inviteNote = " Speaker portal invitation emailed to {$speaker->contact_email}.";
            } catch (RuntimeException $e) {
                [$flash, $inviteNote] = ['error', ' '.$e->getMessage()];
            }
        }

        $event = $request->filled('for_event_id') ? Event::find($request->integer('for_event_id')) : null;
        if ($event && $request->user()->can('update', $event)) {
            $event->speakers()->syncWithoutDetaching([
                $speaker->id => ['sort_order' => (int) $event->speakers()->max('event_speaker.sort_order') + 1],
            ]);
            Audit::record('event.speaker_linked', subject: $speaker, eventId: $event->id);

            return redirect()->route('admin.events.speakers.index', $event)->with($flash, "{$speaker->name} created and added to this event.{$inviteNote}");
        }

        return redirect()->route('admin.speakers.index')->with($flash, "Speaker created.{$inviteNote}");
    }

    public function edit(Speaker $speaker): Response
    {
        $this->authorize('update', $speaker);

        $visibleEventIds = Event::visibleTo(Auth::user())->pluck('id');

        return Inertia::render('Admin/Speakers/Edit', [
            // contact_email is $hidden on the model (it must never reach a
            // public page), so the admin form gets it explicitly.
            'speaker' => [...$speaker->toArray(), 'contact_email' => $speaker->contact_email],
            // Speaker portal: account, pending invitation, or neither.
            'portal' => $speaker->portalSummary(),
            // Read-only: where this speaker currently appears. Linking is
            // done from each event's Speakers page.
            'linkedEvents' => $speaker->events()
                ->whereIn('events.id', $visibleEventIds)
                ->orderByDesc('starts_at')
                ->get()
                ->map(fn (Event $event) => [
                    'id' => $event->id,
                    'name' => $event->name,
                    'sessions' => $speaker->programmeSessions()
                        ->where('event_id', $event->id)
                        ->orderBy('start_time')
                        ->pluck('title'),
                ]),
        ]);
    }

    public function update(UpdateSpeakerRequest $request, Speaker $speaker): RedirectResponse
    {
        $data = $request->safe()->only(['user_id', 'name', 'title', 'organisation', 'contact_email', 'bio']);
        $previousPhotoPath = $speaker->photo_path;

        if ($request->hasFile('photo')) {
            $data['photo_path'] = $request->file('photo')->store('speakers', 'public');
            $data['photo_alt'] = $request->validated('photo_alt');
            $data['photo_approved'] = false;
        } elseif ($request->boolean('remove_photo')) {
            $data['photo_path'] = null;
            $data['photo_alt'] = null;
            $data['photo_approved'] = false;
        } elseif ($request->has('photo_approved')) {
            // Approving an existing photo (no new file this request) is
            // the one case photo_approved is taken from the request as-is.
            $data['photo_approved'] = $request->boolean('photo_approved');
        }

        $speaker->update($data);

        if (($request->hasFile('photo') || $request->boolean('remove_photo')) && $previousPhotoPath && $previousPhotoPath !== $speaker->photo_path) {
            Storage::disk('public')->delete($previousPhotoPath);
            Audit::record('speaker.photo_replaced', subject: $speaker, before: ['photo_path' => $previousPhotoPath]);
        }

        return redirect()->route('admin.speakers.index')->with('success', 'Speaker updated.');
    }

    public function destroy(Speaker $speaker): RedirectResponse
    {
        $this->authorize('delete', $speaker);

        $photoPath = $speaker->photo_path;

        $speaker->delete();

        if ($photoPath) {
            Storage::disk('public')->delete($photoPath);
        }

        return redirect()->route('admin.speakers.index')->with('success', 'Speaker deleted.');
    }
}
