<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PartnerFeature;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventPartner;
use App\Models\Partner;
use App\Models\PartnerConnection;
use App\Models\PartnerTier;
use App\Services\PortalInvitationService;
use App\Support\Audit;
use App\Support\ReferralReport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Event → Partners: the event's tiers (what each level unlocks), the
 * partners taken on for it and their tier, publishing, inviting a
 * partner's team to its portal, and what their referrals came to.
 */
class EventPartnerController extends Controller
{
    public function index(Event $event): Response
    {
        $this->authorize('view', $event);

        $eventPartners = $event->eventPartners()->with(['partner', 'tier'])->get()
            ->sortBy(fn (EventPartner $ep) => [$ep->tier?->sort_order ?? PHP_INT_MAX, $ep->sort_order, $ep->partner->name])
            ->values();

        $leadCounts = PartnerConnection::where('event_id', $event->id)->active()
            ->selectRaw('partner_id, count(*) as total')->groupBy('partner_id')->pluck('total', 'partner_id');

        return Inertia::render('Admin/Events/Partners', [
            'event' => ['id' => $event->id, 'name' => $event->name],
            'features' => PartnerFeature::options(),
            'tiers' => $event->partnerTiers()->withCount('eventPartners')->get()->map(fn (PartnerTier $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'sort_order' => $t->sort_order,
                'features' => $t->features ?? [],
                'comp_tickets' => $t->comp_tickets,
                'partners_count' => $t->event_partners_count,
            ]),
            'partners' => $eventPartners->map(fn (EventPartner $ep) => [
                'id' => $ep->id,
                'partner_id' => $ep->partner_id,
                'slug' => $ep->partner->slug,
                'name' => $ep->partner->name,
                'logo_url' => $ep->partner->logo_path ? Storage::disk('public')->url($ep->partner->logo_path) : null,
                'logo_approved' => $ep->partner->logo_approved,
                'tier_id' => $ep->partner_tier_id,
                'tier' => $ep->tier?->name,
                'stand_label' => $ep->stand_label,
                'blurb' => $ep->blurb,
                'is_published' => $ep->is_published,
                'sort_order' => $ep->sort_order,
                'members_count' => $ep->partner->members()->count(),
                'pending_invitations' => $ep->partner->portalInvitations()
                    ->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())->count(),
                'leads' => (int) ($leadCounts[$ep->partner_id] ?? 0),
                // Portal team and invitations waiting (Partner::portalSummary()).
                'portal' => $ep->partner->portalSummary(),
            ]),
            'library' => Partner::whereNotIn('id', $eventPartners->pluck('partner_id'))
                ->orderBy('name')->get(['id', 'name'])
                ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name]),
            'referrals' => ReferralReport::forEvent($event),
        ]);
    }

    // --- Tiers ------------------------------------------------------------

    public function storeTier(Request $request, Event $event): RedirectResponse
    {
        $this->authorize('update', $event);

        $tier = $event->partnerTiers()->create($this->tierData($request) + [
            'sort_order' => (int) $event->partnerTiers()->max('sort_order') + 1,
        ]);

        Audit::record('partner_tier.created', subject: $tier, eventId: $event->id, after: $tier->only(['name', 'features', 'comp_tickets']));

        return back()->with('success', "Tier “{$tier->name}” added.");
    }

    public function updateTier(Request $request, Event $event, PartnerTier $partnerTier): RedirectResponse
    {
        $this->authorize('update', $event);

        $before = $partnerTier->only(['name', 'features', 'comp_tickets']);
        $partnerTier->update($this->tierData($request) + ['sort_order' => $request->integer('sort_order', $partnerTier->sort_order)]);

        Audit::record('partner_tier.updated', subject: $partnerTier, eventId: $event->id, before: $before, after: $partnerTier->only(['name', 'features', 'comp_tickets']));

        return back()->with('success', "Tier “{$partnerTier->name}” saved.");
    }

    public function destroyTier(Event $event, PartnerTier $partnerTier): RedirectResponse
    {
        $this->authorize('update', $event);

        // Partners on it lose its features (no tier = no features) until
        // staff place them on another.
        $name = $partnerTier->name;
        $partnerTier->delete();

        Audit::record('partner_tier.deleted', eventId: $event->id, before: ['name' => $name]);

        return back()->with('success', "Tier “{$name}” removed. Partners on it have no features until you choose another tier.");
    }

    private function tierData(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'features' => ['array'],
            'features.*' => ['string', Rule::in(PartnerFeature::values())],
            'comp_tickets' => ['nullable', 'integer', 'min:0', 'max:500'],
        ]);

        return [
            'name' => $data['name'],
            'features' => array_values(array_unique($data['features'] ?? [])),
            'comp_tickets' => (int) ($data['comp_tickets'] ?? 0),
        ];
    }

    // --- Partners on the event ---------------------------------------------

    public function attach(Request $request, Event $event): RedirectResponse
    {
        $this->authorize('update', $event);

        $data = $request->validate([
            'partner_id' => ['required', 'integer', 'exists:partners,id', Rule::unique('event_partner')->where('event_id', $event->id)],
            'partner_tier_id' => ['nullable', 'integer', Rule::exists('partner_tiers', 'id')->where('event_id', $event->id)],
        ]);

        $eventPartner = $event->eventPartners()->create([
            'partner_id' => $data['partner_id'],
            'partner_tier_id' => $data['partner_tier_id'] ?? null,
            'is_published' => false,
            'sort_order' => (int) $event->eventPartners()->max('sort_order') + 1,
        ]);

        Audit::record('event_partner.attached', subject: $eventPartner->partner, eventId: $event->id, after: ['tier_id' => $eventPartner->partner_tier_id]);

        return back()->with('success', "{$eventPartner->partner->name} added to this event. Publish them when they're ready to appear.");
    }

    public function update(Request $request, Event $event, EventPartner $eventPartner): RedirectResponse
    {
        $this->authorize('update', $event);

        $data = $request->validate([
            'partner_tier_id' => ['nullable', 'integer', Rule::exists('partner_tiers', 'id')->where('event_id', $event->id)],
            'stand_label' => ['nullable', 'string', 'max:80'],
            'blurb' => ['nullable', 'string', 'max:2000'],
            'is_published' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $before = $eventPartner->only(['partner_tier_id', 'is_published']);
        $eventPartner->update($data);

        Audit::record('event_partner.updated', subject: $eventPartner->partner, eventId: $event->id, before: $before, after: $eventPartner->only(['partner_tier_id', 'is_published']));

        return back()->with('success', "{$eventPartner->partner->name} saved.");
    }

    public function detach(Event $event, EventPartner $eventPartner): RedirectResponse
    {
        $this->authorize('update', $event);

        $name = $eventPartner->partner->name;
        $partnerId = $eventPartner->partner_id;
        $eventPartner->delete();

        Audit::record('event_partner.detached', eventId: $event->id, before: ['partner_id' => $partnerId, 'name' => $name]);

        return back()->with('success', "{$name} removed from this event. Leads already shared with them stay theirs until each attendee withdraws.");
    }

    /** Invite someone from the partner's side to its portal (owner or team member). */
    public function invite(Request $request, Event $event, EventPartner $eventPartner, PortalInvitationService $invitations): RedirectResponse
    {
        $this->authorize('update', $event);

        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'role' => ['required', Rule::in(['owner', 'member'])],
        ]);

        try {
            $invitation = $invitations->invitePartnerMember($eventPartner->partner, $data['email'], $data['role'], $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Invitation emailed to {$invitation->email}. It works until {$invitation->expires_at->format('j M Y')}.");
    }
}
