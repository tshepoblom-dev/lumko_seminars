<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreResourceRequest;
use App\Http\Requests\Admin\UpdateResourceRequest;
use App\Models\Event;
use App\Models\Resource;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class ResourceController extends Controller
{
    public function index(Event $event): Response
    {
        $this->authorize('viewAny', Resource::class);
        $this->authorize('view', $event);

        return Inertia::render('Admin/Resources/Index', [
            'resources' => $event->resources()->withCount('views')
                ->with(['event', 'speaker:id,name', 'partner:id,name'])
                ->latest()
                ->get()
                ->map(fn (Resource $r) => [
                    'id' => $r->id,
                    'event' => $r->event->name,
                    'title' => $r->title,
                    'category_label' => $r->category->label(),
                    'is_published' => $r->is_published,
                    'views_count' => $r->views_count,
                    // Uploaded from the speaker or partner portal (published
                    // straight away; staff can unpublish).
                    'shared_by' => $r->speaker ? "Speaker: {$r->speaker->name}" : ($r->partner ? "Partner: {$r->partner->name}" : null),
                ]),
        ]);
    }

    public function create(Event $event): Response
    {
        $this->authorize('create', Resource::class);
        $this->authorize('update', $event);

        return Inertia::render('Admin/Resources/Create');
    }

    public function store(StoreResourceRequest $request, Event $event): RedirectResponse
    {
        $path = $request->hasFile('file')
            ? $request->file('file')->store('resources', 'local')
            : null;

        Resource::create([
            ...$request->safe()->only(['event_id', 'title', 'description', 'category', 'external_url']),
            'file_path' => $path,
            'file_original_name' => $request->hasFile('file') ? $request->file('file')->getClientOriginalName() : null,
            'created_by' => $request->user()->id,
        ]);

        return redirect()->route('admin.resources.index', $event)->with('success', 'Resource created.');
    }

    public function edit(Event $event, Resource $resource): Response
    {
        $this->authorize('update', $resource);

        return Inertia::render('Admin/Resources/Edit', [
            'resource' => $resource,
        ]);
    }

    public function update(UpdateResourceRequest $request, Event $event, Resource $resource): RedirectResponse
    {
        $data = $request->safe()->only(['event_id', 'title', 'description', 'category', 'external_url']);
        $previousFilePath = $resource->file_path;

        if ($request->hasFile('file')) {
            $data['file_path'] = $request->file('file')->store('resources', 'local');
            $data['file_original_name'] = $request->file('file')->getClientOriginalName();
        }

        $resource->update($data);

        // B3 — orphan cleanup: replacing a resource's file removes the old one.
        if ($request->hasFile('file') && $previousFilePath && $previousFilePath !== $resource->file_path) {
            Storage::disk('local')->delete($previousFilePath);
            Audit::record('resource.file_replaced', subject: $resource, eventId: $resource->event_id, before: ['file_path' => $previousFilePath]);
        }

        return redirect()->route('admin.resources.index', $event)->with('success', 'Resource updated.');
    }

    public function destroy(Event $event, Resource $resource): RedirectResponse
    {
        $this->authorize('delete', $resource);

        $filePath = $resource->file_path;
        $eventId = $resource->event_id;

        $resource->delete();

        // B3 — orphan cleanup: deleting the record deletes the file too.
        if ($filePath) {
            Storage::disk('local')->delete($filePath);
            Audit::record('resource.deleted', eventId: $eventId, before: ['file_path' => $filePath]);
        }

        return redirect()->route('admin.resources.index', $event)->with('success', 'Resource deleted.');
    }

    public function publish(Event $event, Resource $resource): RedirectResponse
    {
        $this->authorize('update', $resource);

        $resource->update(['is_published' => true, 'published_at' => now()]);

        return back()->with('success', 'Resource published.');
    }

    public function unpublish(Event $event, Resource $resource): RedirectResponse
    {
        $this->authorize('update', $resource);

        $resource->update(['is_published' => false]);

        return back()->with('success', 'Resource unpublished.');
    }
}
