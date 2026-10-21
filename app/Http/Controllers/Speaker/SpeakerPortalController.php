<?php

namespace App\Http\Controllers\Speaker;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Speaker\Concerns\ResolvesSpeakerEvent;
use App\Models\Event;
use App\Models\ProgrammeSession;
use App\Services\SpeakerReport;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The speaker portal: the speaker's schedule across events, their public
 * profile, and their report (the people who chose to connect with them +
 * the session report). The stage, green room and resources have their own
 * controllers.
 */
class SpeakerPortalController extends Controller
{
    use ResolvesSpeakerEvent;

    public function dashboard(Request $request): Response
    {
        $speaker = $this->speaker($request);

        $events = $speaker->events()
            ->whereIn('status', ['published', 'completed'])
            ->orderBy('starts_at')
            ->get()
            ->map(function (Event $event) use ($speaker) {
                $sessions = $speaker->programmeSessions()
                    ->where('event_id', $event->id)
                    ->with('speakers:id,name')
                    ->orderBy('start_time')
                    ->get();

                $hasStage = $event->livestream_provider?->value === 'agora'
                    && $event->livestream_channel_name
                    && ! $event->ends_at?->copy()->addHours(2)->isPast();

                return [
                    'id' => $event->id,
                    'name' => $event->name,
                    'starts_at' => $event->starts_at?->toIso8601String(),
                    'ends_at' => $event->ends_at?->toIso8601String(),
                    'venue_name' => $event->venue_name,
                    'is_live' => $event->is_live,
                    'has_ended' => $event->ends_at?->isPast() ?? false,
                    'has_stage' => (bool) $hasStage,
                    'sessions' => $sessions->map(fn (ProgrammeSession $s) => [
                        'id' => $s->id,
                        'title' => $s->title,
                        'start_time' => $s->start_time?->toIso8601String(),
                        'end_time' => $s->end_time?->toIso8601String(),
                        'status' => $s->status,
                        'with' => $s->speakers->where('id', '!=', $speaker->id)->pluck('name')->values(),
                    ])->values(),
                    'connections_count' => $speaker->connections()->where('event_id', $event->id)->active()->count(),
                    'resources_count' => $event->resources()->where('speaker_id', $speaker->id)->count(),
                ];
            });

        return Inertia::render('Speaker/Dashboard', [
            'speaker' => [
                'name' => $speaker->name,
                'title' => $speaker->title,
                'organisation' => $speaker->organisation,
                'has_bio' => filled($speaker->bio),
                'photo_url' => $speaker->photo_path ? Storage::disk('public')->url($speaker->photo_path) : null,
                'photo_approved' => $speaker->photo_approved,
            ],
            'events' => $events,
        ]);
    }

    public function editProfile(Request $request): Response
    {
        $speaker = $this->speaker($request);

        return Inertia::render('Speaker/Profile', [
            'speaker' => [
                'name' => $speaker->name,
                'title' => $speaker->title,
                'organisation' => $speaker->organisation,
                'bio' => $speaker->bio,
                'photo_url' => $speaker->photo_path ? Storage::disk('public')->url($speaker->photo_path) : null,
                'photo_alt' => $speaker->photo_alt,
                'photo_approved' => $speaker->photo_approved,
            ],
        ]);
    }

    /**
     * The speaker edits their own public profile. A new photo goes back to
     * "awaiting approval" (staff approve photos before they're public, as
     * for photos staff upload).
     */
    public function updateProfile(Request $request): RedirectResponse
    {
        $speaker = $this->speaker($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'title' => ['nullable', 'string', 'max:255'],
            'organisation' => ['nullable', 'string', 'max:255'],
            'bio' => ['nullable', 'string', 'max:5000'],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'photo_alt' => ['nullable', 'required_with:photo', 'string', 'max:255'],
            'remove_photo' => ['nullable', 'boolean'],
        ]);

        $data = collect($validated)->only(['name', 'title', 'organisation', 'bio'])->all();
        $previousPhotoPath = $speaker->photo_path;

        if ($request->hasFile('photo')) {
            $data['photo_path'] = $request->file('photo')->store('speakers', 'public');
            $data['photo_alt'] = $validated['photo_alt'];
            $data['photo_approved'] = false;
        } elseif ($request->boolean('remove_photo')) {
            $data['photo_path'] = null;
            $data['photo_alt'] = null;
            $data['photo_approved'] = false;
        } elseif ($request->filled('photo_alt') && $speaker->photo_path) {
            $data['photo_alt'] = $validated['photo_alt'];
        }

        $before = $speaker->only(['name', 'title', 'organisation']);
        $speaker->update($data);

        if (($request->hasFile('photo') || $request->boolean('remove_photo')) && $previousPhotoPath && $previousPhotoPath !== $speaker->photo_path) {
            Storage::disk('public')->delete($previousPhotoPath);
        }

        Audit::record('speaker.profile_updated', subject: $speaker, before: $before, after: [
            ...$speaker->only(['name', 'title', 'organisation']),
            'photo_changed' => $request->hasFile('photo') || $request->boolean('remove_photo'),
        ]);

        return back()->with('success', $request->hasFile('photo')
            ? 'Profile saved. Your new photo appears on the event site once our team approves it.'
            : 'Profile saved.');
    }

    /** The speaker's report for one event, inside the portal. */
    public function report(Request $request, Event $event, SpeakerReport $report): SymfonyResponse
    {
        $speaker = $this->speaker($request);
        $this->assertSpeaksAt($speaker, $event);

        $data = $report->build($event, $speaker);

        Audit::record('speaker.report_viewed', subject: $speaker, eventId: $event->id, after: [
            'contacts_shown' => count($data['contacts']),
            'via' => 'portal',
        ]);

        $response = Inertia::render('Public/SpeakerReport', [
            ...$data,
            'csvUrl' => route('speaker.events.report.contacts', $event),
            'preview' => false,
            'backUrl' => route('speaker.dashboard'),
        ])->toResponse($request);

        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    public function reportContacts(Request $request, Event $event, SpeakerReport $report): StreamedResponse
    {
        $speaker = $this->speaker($request);
        $this->assertSpeaksAt($speaker, $event);

        Audit::record('speaker.contacts_downloaded', subject: $speaker, eventId: $event->id, after: [
            'rows' => $report->contacts($event, $speaker)->count(),
            'via' => 'portal',
        ]);

        return $report->contactsCsv($event, $speaker);
    }
}
