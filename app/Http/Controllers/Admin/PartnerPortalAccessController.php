<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventPartner;
use App\Models\Partner;
use App\Models\PortalInvitation;
use App\Models\User;
use App\Services\PortalInvitationService;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Partner portal access, from staff's side: invite someone to a partner's
 * team (owner or team member), resend or cancel a pending invitation,
 * remove a team member, and invite owners for a whole event's partners at
 * once. Partner owners manage their own team from the portal (Partner\
 * TeamController); this is for staff, on the partner library and an
 * event's Partners page.
 */
class PartnerPortalAccessController extends Controller
{
    public function __construct(private PortalInvitationService $invitations) {}

    public function invite(Request $request, Partner $partner): RedirectResponse
    {
        $this->authorize('update', $partner);

        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'role' => ['required', Rule::in(['owner', 'member'])],
        ]);

        try {
            $invitation = $this->invitations->invitePartnerMember($partner, $data['email'], $data['role'], $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Invitation to {$partner->name}'s portal emailed to {$invitation->email}. It works until {$invitation->expires_at->format('j M Y')}.");
    }

    /** A fresh link to the same person and role; the old one stops working. */
    public function resend(Request $request, Partner $partner, PortalInvitation $invitation): RedirectResponse
    {
        $this->authorize('update', $partner);
        abort_unless($invitation->partner_id === $partner->id && $invitation->isPending(), 404);

        try {
            $fresh = $this->invitations->invitePartnerMember($partner, $invitation->email, $invitation->member_role ?: 'member', $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Invitation resent to {$fresh->email}. The earlier link no longer works.");
    }

    public function cancel(Partner $partner, PortalInvitation $invitation): RedirectResponse
    {
        $this->authorize('update', $partner);
        abort_unless($invitation->partner_id === $partner->id && $invitation->isPending(), 404);

        $this->invitations->revoke($invitation);

        return back()->with('success', "The invitation to {$invitation->email} no longer works.");
    }

    /** Takes someone off the partner's team. Their account stays. */
    public function removeMember(Partner $partner, User $user): RedirectResponse
    {
        $this->authorize('update', $partner);
        abort_unless($partner->hasMember($user), 404);

        $partner->members()->detach($user->id);
        Audit::record('partner.member_removed', subject: $partner, before: ['user_id' => $user->id, 'email' => $user->email, 'by' => 'staff']);

        return back()->with('success', "{$user->name} no longer has access to {$partner->name}'s portal. Their account still exists.");
    }

    /**
     * Event → Partners: invite the main contact, as owner, of every partner
     * on this event that has a contact email, nobody on its team and no
     * invitation waiting. One failed email doesn't stop the rest.
     */
    public function inviteOwners(Request $request, Event $event): RedirectResponse
    {
        $this->authorize('update', $event);

        $eligible = EventPartner::with('partner')->where('event_id', $event->id)->get()
            ->pluck('partner')
            ->filter(fn (?Partner $p) => $p && $p->portalSummary()['can_invite_owner'])
            ->values();

        if ($eligible->isEmpty()) {
            return back()->with('info', 'Every partner with a contact email already has a team or an invitation waiting.');
        }

        $sent = [];
        $failed = [];
        foreach ($eligible as $partner) {
            try {
                $this->invitations->invitePartnerMember($partner, $partner->contact_email, 'owner', $request->user());
                $sent[] = $partner->name;
            } catch (RuntimeException) {
                $failed[] = $partner->name;
            }
        }

        Audit::record('partner.portal_invite_owners', subject: $event, eventId: $event->id, after: [
            'sent' => count($sent),
            'failed' => count($failed),
        ]);

        $message = 'Invited the owners of '.count($sent).' '.(count($sent) === 1 ? 'partner' : 'partners').' to their portal.';

        if ($failed) {
            return back()->with('error', $message.' '.count($failed).' couldn\'t be sent: '.implode(', ', $failed).'. Check the mail settings and try them again.');
        }

        return back()->with('success', $message);
    }
}
