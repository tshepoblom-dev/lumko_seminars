<?php

namespace App\Http\Controllers\Partner;

use App\Enums\PartnerFeature;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Partner\Concerns\ResolvesPartnerEvent;
use App\Models\Event;
use App\Models\Partner;
use App\Models\Resource;
use App\Support\Audit;
use App\Support\ResourceRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Brochures, offers and links a partner shares with an event's attendees
 * (tier feature `resources`). Published straight away like speaker
 * uploads, without emailing every attendee; staff can unpublish. They show
 * on the partner's page and on the attendee Resources page.
 */
class PartnerResourceController extends Controller
{
    use ResolvesPartnerEvent;

    public function index(Partner $partner, Event $event): Response
    {
        $eventPartner = $this->eventPartner($partner, $event);
        $this->requireFeature($eventPartner, PartnerFeature::Resources);

        return Inertia::render('Partner/Resources', [
            ...$this->portalProps($partner, $event, $eventPartner),
            'resources' => $event->resources()->where('partner_id', $partner->id)->withCount('views')->latest()->get()
                ->map(fn (Resource $r) => [
                    'id' => $r->id,
                    'title' => $r->title,
                    'description' => $r->description,
                    'is_external' => (bool) $r->external_url,
                    'file_name' => $r->file_original_name,
                    'is_published' => $r->is_published,
                    'views_count' => $r->views_count,
                ]),
        ]);
    }

    public function store(Request $request, Partner $partner, Event $event): RedirectResponse
    {
        $eventPartner = $this->eventPartner($partner, $event);
        $this->requireFeature($eventPartner, PartnerFeature::Resources);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'file' => ResourceRules::file(),
            'external_url' => ResourceRules::externalUrl(),
        ]);

        if (! $request->hasFile('file') && blank($data['external_url'] ?? null)) {
            return back()->withErrors(['file' => 'Provide either a file or a link.'])->withInput();
        }

        $resource = Resource::create([
            'event_id' => $event->id,
            'created_by' => $request->user()->id,
            'partner_id' => $partner->id,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'category' => 'during_event',
            'external_url' => $data['external_url'] ?? null,
            'file_path' => $request->hasFile('file') ? $request->file('file')->store('resources', 'local') : null,
            'file_original_name' => $request->hasFile('file') ? $request->file('file')->getClientOriginalName() : null,
            'is_published' => true,
            'published_at' => now(),
            'release_mode' => 'immediate',
        ]);

        Audit::record('resource.created_by_partner', subject: $resource, eventId: $event->id, after: [
            'partner_id' => $partner->id,
            'title' => $resource->title,
        ]);

        return back()->with('success', 'Shared. Attendees can open it from your partner page and Resources.');
    }

    public function destroy(Partner $partner, Event $event, Resource $resource): RedirectResponse
    {
        $this->eventPartner($partner, $event);
        abort_unless($resource->event_id === $event->id && $resource->partner_id === $partner->id, 404);

        $filePath = $resource->file_path;
        $title = $resource->title;
        $resource->delete();

        if ($filePath) {
            Storage::disk('local')->delete($filePath);
        }

        Audit::record('resource.deleted_by_partner', eventId: $event->id, before: ['partner_id' => $partner->id, 'title' => $title]);

        return back()->with('success', 'Removed.');
    }
}
