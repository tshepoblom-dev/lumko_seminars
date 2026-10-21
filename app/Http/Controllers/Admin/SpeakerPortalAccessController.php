<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Speaker;
use App\Services\PortalInvitationService;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Speaker portal access, from the speakers library, a speaker's edit page
 * and Event → Speakers: invite a speaker (an emailed, single-use link to
 * their contact_email), invite a whole event line-up at once, or take
 * portal access away again. Removing access unlinks the account; it
 * doesn't delete it.
 */
class SpeakerPortalAccessController extends Controller
{
    public function invite(Request $request, Speaker $speaker, PortalInvitationService $invitations): RedirectResponse
    {
        $this->authorize('update', $speaker);

        try {
            $invitation = $invitations->inviteSpeaker($speaker, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Portal invitation emailed to {$speaker->name} ({$invitation->email}). It works until {$invitation->expires_at->format('j M Y')}.");
    }

    /**
     * Event → Speakers: invite everyone on this event's line-up who has a
     * contact email and hasn't been invited yet (no account, no pending
     * invitation). One failed email doesn't stop the rest.
     */
    public function inviteAll(Request $request, Event $event, PortalInvitationService $invitations): RedirectResponse
    {
        $this->authorize('update', $event);

        $eligible = $event->speakers()->get()
            ->filter(fn (Speaker $s) => $s->portalSummary()['status'] === 'not_invited' && filled($s->contact_email))
            ->values();

        if ($eligible->isEmpty()) {
            return back()->with('info', 'Everyone with a contact email has already been invited or has an account.');
        }

        $sent = [];
        $failed = [];
        foreach ($eligible as $speaker) {
            try {
                $invitations->inviteSpeaker($speaker, $request->user());
                $sent[] = $speaker->name;
            } catch (RuntimeException) {
                $failed[] = $speaker->name;
            }
        }

        Audit::record('speaker.portal_invite_all', subject: $event, eventId: $event->id, after: [
            'sent' => count($sent),
            'failed' => count($failed),
        ]);

        $message = 'Invited '.count($sent).' '.(count($sent) === 1 ? 'speaker' : 'speakers').' to the speaker portal.';

        if ($failed) {
            return back()->with('error', $message.' '.count($failed).' couldn\'t be sent: '.implode(', ', $failed).'. Check the mail settings and try them again.');
        }

        return back()->with('success', $message);
    }

    public function revoke(Speaker $speaker): RedirectResponse
    {
        $this->authorize('update', $speaker);

        $before = ['user_id' => $speaker->user_id];
        $speaker->forceFill(['user_id' => null])->save();
        $speaker->portalInvitations()->whereNull('accepted_at')->whereNull('revoked_at')->update(['revoked_at' => now()]);

        Audit::record('speaker.portal_access_removed', subject: $speaker, before: $before);

        return back()->with('success', "{$speaker->name} no longer has speaker portal access. Their account still exists.");
    }
}
