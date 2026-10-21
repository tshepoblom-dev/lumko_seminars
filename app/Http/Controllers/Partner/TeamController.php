<?php

namespace App\Http\Controllers\Partner;

use App\Http\Controllers\Controller;
use App\Models\Partner;
use App\Models\User;
use App\Services\PortalInvitationService;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * The partner's team: who can use its portal (for instance the reps who
 * scan tickets at the stand). Everyone on the team sees the list; owners
 * invite and remove people.
 */
class TeamController extends Controller
{
    public function index(Request $request, Partner $partner): Response
    {
        return Inertia::render('Partner/Team', [
            'partner' => ['slug' => $partner->slug, 'name' => $partner->name],
            'canManage' => $request->user()->can('manageTeam', $partner),
            'members' => $partner->members()->orderBy('users.name')->get()->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->pivot->role,
                'is_you' => $u->id === $request->user()->id,
            ]),
            'invitations' => $partner->portalInvitations()
                ->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())
                ->latest()->get()
                ->map(fn ($i) => [
                    'id' => $i->id,
                    'email' => $i->email,
                    'role' => $i->member_role,
                    'expires_at' => $i->expires_at->toIso8601String(),
                ]),
        ]);
    }

    public function invite(Request $request, Partner $partner, PortalInvitationService $invitations): RedirectResponse
    {
        $this->authorize('manageTeam', $partner);

        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'role' => ['required', Rule::in(['owner', 'member'])],
        ]);

        try {
            $invitation = $invitations->invitePartnerMember($partner, $data['email'], $data['role'], $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Invitation emailed to {$invitation->email}.");
    }

    public function remove(Request $request, Partner $partner, User $user): RedirectResponse
    {
        $this->authorize('manageTeam', $partner);
        abort_unless($partner->hasMember($user), 404);

        // A partner always keeps at least one owner.
        if ($partner->isOwner($user) && $partner->members()->wherePivot('role', 'owner')->count() === 1) {
            return back()->with('error', 'Add another owner before removing the last one.');
        }

        $partner->members()->detach($user->id);
        Audit::record('partner.member_removed', subject: $partner, before: ['user_id' => $user->id, 'email' => $user->email]);

        return back()->with('success', "{$user->name} no longer has access to {$partner->name}'s portal.");
    }

    public function revokeInvitation(Partner $partner, int $invitation, PortalInvitationService $invitations): RedirectResponse
    {
        $this->authorize('manageTeam', $partner);

        $pending = $partner->portalInvitations()->whereKey($invitation)->whereNull('accepted_at')->whereNull('revoked_at')->firstOrFail();
        $invitations->revoke($pending);

        return back()->with('success', "The invitation to {$pending->email} no longer works.");
    }
}
