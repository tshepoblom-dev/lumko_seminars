<?php

namespace App\Http\Controllers\Speaker;

use App\Enums\ResourceCategory;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Speaker\Concerns\ResolvesSpeakerEvent;
use App\Models\Event;
use App\Models\ProgrammeSession;
use App\Models\Resource;
use App\Support\Audit;
use App\Support\ResourceRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Slides, papers and links a speaker shares with an event's attendees.
 * They're published straight away (owner decision); staff can unpublish
 * them from Event → Resources. The speaker chooses when attendees see
 * them: now, or once their session has ended.
 */
class SpeakerResourceController extends Controller
{
    use ResolvesSpeakerEvent;

    public function index(Request $request, Event $event): Response
    {
        $speaker = $this->speaker($request);
        $this->assertSpeaksAt($speaker, $event);

        $sessions = $speaker->programmeSessions()
            ->where('event_id', $event->id)
            ->orderBy('start_time')
            ->get(['programme_sessions.id', 'title', 'start_time', 'end_time']);

        $now = now();

        return Inertia::render('Speaker/Resources', [
            'event' => ['id' => $event->id, 'name' => $event->name],
            'sessions' => $sessions->map(fn (ProgrammeSession $s) => [
                'id' => $s->id,
                'title' => $s->title,
                'end_time' => $s->end_time?->toIso8601String(),
            ])->values(),
            'categories' => collect(ResourceCategory::cases())->map(fn ($c) => ['value' => $c->value, 'label' => $c->label()])->values(),
            'resources' => $event->resources()
                ->where('speaker_id', $speaker->id)
                ->withCount('views')
                ->latest()
                ->get()
                ->map(fn (Resource $r) => [
                    'id' => $r->id,
                    'title' => $r->title,
                    'description' => $r->description,
                    'category_label' => $r->category->label(),
                    'is_external' => (bool) $r->external_url,
                    'file_name' => $r->file_original_name,
                    'is_published' => $r->is_published,
                    'is_available' => $r->isReleased($event, $event->currentSession, $now),
                    'release_at' => $r->release_at?->toIso8601String(),
                    'views_count' => $r->views_count,
                ]),
        ]);
    }

    public function store(Request $request, Event $event): RedirectResponse
    {
        $speaker = $this->speaker($request);
        $this->assertSpeaksAt($speaker, $event);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'category' => ['required', Rule::enum(ResourceCategory::class)],
            'file' => ResourceRules::file(),
            'external_url' => ResourceRules::externalUrl(),
            // "now", or a session of theirs: available once it has ended.
            'release' => ['required', 'string'],
        ]);

        if (! $request->hasFile('file') && blank($data['external_url'] ?? null)) {
            return back()->withErrors(['file' => 'Provide either a file or a link.'])->withInput();
        }

        $releaseAt = null;

        if ($data['release'] !== 'now') {
            $session = $speaker->programmeSessions()->where('event_id', $event->id)
                ->whereKey((int) $data['release'])->first();

            if (! $session) {
                return back()->withErrors(['release' => 'Choose one of your sessions.'])->withInput();
            }

            $releaseAt = $session->end_time ?? $event->ends_at;
        }

        $path = $request->hasFile('file') ? $request->file('file')->store('resources', 'local') : null;

        // Timed release uses the "at a time" mode (before_event + release_at):
        // the resource stays available after the session, unlike
        // during_session, which shows it only while the session is current.
        $resource = Resource::create([
            'event_id' => $event->id,
            'created_by' => $request->user()->id,
            'speaker_id' => $speaker->id,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'category' => $data['category'],
            'external_url' => $data['external_url'] ?? null,
            'file_path' => $path,
            'file_original_name' => $request->hasFile('file') ? $request->file('file')->getClientOriginalName() : null,
            'is_published' => true,
            'published_at' => now(),
            'release_mode' => $releaseAt ? 'before_event' : 'immediate',
            'release_at' => $releaseAt,
        ]);

        Audit::record('resource.created_by_speaker', subject: $resource, eventId: $event->id, after: [
            'speaker_id' => $speaker->id,
            'title' => $resource->title,
            'release_at' => $releaseAt?->toIso8601String(),
        ]);

        return back()->with('success', $releaseAt
            ? 'Shared. Attendees can open it once your session ends.'
            : 'Shared. Attendees can open it now from Resources.');
    }

    public function destroy(Request $request, Event $event, Resource $resource): RedirectResponse
    {
        $speaker = $this->speaker($request);
        $this->assertSpeaksAt($speaker, $event);
        abort_unless($resource->event_id === $event->id && $resource->speaker_id === $speaker->id, 404);

        $filePath = $resource->file_path;
        $resource->delete();

        if ($filePath) {
            Storage::disk('local')->delete($filePath);
        }

        Audit::record('resource.deleted_by_speaker', eventId: $event->id, before: [
            'speaker_id' => $speaker->id,
            'title' => $resource->title,
            'file_path' => $filePath,
        ]);

        return back()->with('success', 'Removed. Attendees can no longer open it.');
    }
}
