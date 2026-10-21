<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Responses\SafeFileResponse;
use App\Models\Event;
use App\Models\Resource;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ResourceController extends Controller
{
    /**
     * The event EnsureEventAttendee gated (not "the user's latest
     * registration"), and only what its release timing has made available
     * — the same rule as the dashboard's count (Resource::availableFor()).
     */
    public function index(Request $request): Response
    {
        $event = $request->attributes->get('currentEvent');

        $resources = $event instanceof Event
            ? Resource::availableFor($event, $event->currentSession, now())
                ->load(['speaker:id,name', 'partner:id,name'])
                ->sortBy(fn (Resource $r) => $r->category->value)
                ->values()
                ->map(fn (Resource $r) => [
                    'id' => $r->id,
                    'title' => $r->title,
                    'description' => $r->description,
                    'category' => $r->category->value,
                    'category_label' => $r->category->label(),
                    'is_external' => (bool) $r->external_url,
                    'shared_by' => $r->speaker?->name ?? $r->partner?->name,
                ])
            : collect();

        return Inertia::render('Public/Resources', ['resources' => $resources]);
    }

    public function show(Resource $resource): RedirectResponse|StreamedResponse
    {
        $this->authorize('view', $resource);

        // Staff preview anything; attendees only what has been released
        // (published alone isn't enough for a timed resource).
        if (! Auth::user()->isStaff()) {
            $event = $resource->event;
            abort_unless($resource->isReleased($event, $event->currentSession, now()), 404);
        }

        $resource->views()->create([
            'user_id' => Auth::id(),
            'action' => $resource->file_path ? 'download' : 'view',
        ]);

        if ($resource->external_url) {
            return redirect()->away($resource->external_url);
        }

        // B3 — resources are always a forced download, never inline: we
        // don't want an uploaded PDF/PPTX rendering in-browser where a
        // stray script-like MIME sniff could matter.
        return SafeFileResponse::make('local', $resource->file_path, $resource->file_original_name ?? 'resource');
    }
}
