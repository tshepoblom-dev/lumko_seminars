<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ClientConversion;
use App\Models\Event;
use App\Models\Partner;
use App\Services\PortalInvitationService;
use App\Support\Audit;
use App\Support\ReferralReport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * The partner library: businesses and organisations that support events,
 * shared across events like speakers. Taking a partner on for one event,
 * on a tier, is the event's Partners page (EventPartnerController).
 */
class PartnerController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', Partner::class);

        $visibleEventIds = Event::visibleTo(Auth::user())->pluck('id');

        return Inertia::render('Admin/Partners/Index', [
            'partners' => Partner::orderBy('name')
                ->withCount('members')
                ->with(['events' => fn ($q) => $q->whereIn('events.id', $visibleEventIds)->orderByDesc('starts_at')])
                ->get()
                ->map(fn (Partner $p) => [
                    'id' => $p->id,
                    'slug' => $p->slug,
                    'name' => $p->name,
                    'website' => $p->website,
                    'logo_url' => $p->logo_path ? Storage::disk('public')->url($p->logo_path) : null,
                    'logo_approved' => $p->logo_approved,
                    'members_count' => $p->members_count,
                    'events' => $p->events->map(fn (Event $e) => ['id' => $e->id, 'name' => $e->name]),
                    'portal' => $p->portalSummary(),
                ]),
            // Across every event: what each partner brought in (counts only,
            // plus the deal value staff recorded).
            'referrals' => ReferralReport::forPartners(),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Partner::class);

        return Inertia::render('Admin/Partners/Form', ['partner' => null]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Partner::class);

        $data = $this->validated($request);

        $partner = new Partner(collect($data)->only(['name', 'website', 'description', 'contact_email'])->all());
        if ($request->hasFile('logo')) {
            $partner->logo_path = $request->file('logo')->store('partners', 'public');
            $partner->logo_alt = $data['logo_alt'] ?? $partner->name;
            $partner->logo_approved = true; // staff uploaded it
        }
        $partner->save();

        Audit::record('partner.created', subject: $partner, after: ['name' => $partner->name]);

        // "Invite their main contact as owner" (ticked by default once a
        // contact email is given). A failed email doesn't undo the partner.
        [$flash, $inviteNote] = ['success', ''];
        if ($request->boolean('invite_owner') && filled($partner->contact_email)) {
            try {
                app(PortalInvitationService::class)->invitePartnerMember($partner, $partner->contact_email, 'owner', $request->user());
                $inviteNote = " Portal invitation emailed to {$partner->contact_email} as owner.";
            } catch (RuntimeException $e) {
                [$flash, $inviteNote] = ['error', ' '.$e->getMessage()];
            }
        }

        return redirect()->route('admin.partners.index')->with($flash, "{$partner->name} added. Take them on for an event from that event's Partners page.{$inviteNote}");
    }

    public function edit(Partner $partner): Response
    {
        $this->authorize('update', $partner);

        return Inertia::render('Admin/Partners/Form', [
            'partner' => [
                'slug' => $partner->slug,
                'name' => $partner->name,
                'website' => $partner->website,
                'description' => $partner->description,
                'contact_email' => $partner->contact_email,
                'logo_url' => $partner->logo_path ? Storage::disk('public')->url($partner->logo_path) : null,
                'logo_alt' => $partner->logo_alt,
                'logo_approved' => $partner->logo_approved,
                'conversions' => ClientConversion::where('partner_id', $partner->id)->count(),
            ],
            // Portal team and invitations waiting (Partner::portalSummary()).
            'portal' => $partner->portalSummary(),
        ]);
    }

    public function update(Request $request, Partner $partner): RedirectResponse
    {
        $this->authorize('update', $partner);

        $data = $this->validated($request, $partner);
        $previousLogo = $partner->logo_path;

        $partner->fill(collect($data)->only(['name', 'website', 'description', 'contact_email'])->all());

        if ($request->hasFile('logo')) {
            $partner->logo_path = $request->file('logo')->store('partners', 'public');
            $partner->logo_alt = $data['logo_alt'] ?? $partner->name;
            $partner->logo_approved = true;
        } elseif ($request->boolean('remove_logo')) {
            $partner->logo_path = null;
            $partner->logo_alt = null;
            $partner->logo_approved = false;
        } else {
            if ($request->has('logo_approved')) {
                $partner->logo_approved = $request->boolean('logo_approved');
            }
            if ($request->filled('logo_alt')) {
                $partner->logo_alt = $data['logo_alt'];
            }
        }

        $partner->save();

        if ($previousLogo && $previousLogo !== $partner->logo_path) {
            Storage::disk('public')->delete($previousLogo);
        }

        Audit::record('partner.updated', subject: $partner, after: [
            'name' => $partner->name,
            'logo_approved' => $partner->logo_approved,
        ]);

        return redirect()->route('admin.partners.index')->with('success', "{$partner->name} updated.");
    }

    public function destroy(Partner $partner): RedirectResponse
    {
        $this->authorize('delete', $partner);

        $name = $partner->name;
        $logo = $partner->logo_path;
        $partner->delete();

        if ($logo) {
            Storage::disk('public')->delete($logo);
        }

        Audit::record('partner.deleted', before: ['name' => $name]);

        return redirect()->route('admin.partners.index')->with('success', "{$name} deleted.");
    }

    private function validated(Request $request, ?Partner $partner = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'website' => ['nullable', 'url:https,http', 'max:2048'],
            'description' => ['nullable', 'string', 'max:5000'],
            'contact_email' => ['nullable', 'email:rfc', 'max:255'],
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'logo_alt' => ['nullable', 'string', 'max:255'],
            'logo_approved' => ['nullable', 'boolean'],
            'remove_logo' => ['nullable', 'boolean'],
            // New partner: email the contact an owner invitation once saved.
            'invite_owner' => ['nullable', 'boolean'],
        ]);
    }
}
