<?php

namespace App\Services;

use App\Models\Partner;
use App\Models\PortalInvitation;
use App\Models\Speaker;
use App\Models\User;
use App\Notifications\PortalInvitationNotification;
use App\Support\Audit;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Invitations to the speaker and partner portals. Speakers and partner reps
 * get ordinary user accounts; what makes them a speaker or a partner rep is
 * the link this service creates (speakers.user_id, partner_members), never
 * users.role.
 *
 * The raw token exists only in the email (like SpeakerReportLink): the
 * table keeps its sha256 hash, a new invitation for the same speaker or
 * partner seat replaces the old one, and anything unknown, used, revoked or
 * expired is a plain 404.
 */
class PortalInvitationService
{
    public const EXPIRY_DAYS = 14;

    public function inviteSpeaker(Speaker $speaker, User $invitedBy): PortalInvitation
    {
        if (blank($speaker->contact_email)) {
            throw new RuntimeException("Add a contact email to {$speaker->name}'s profile first.");
        }

        if ($speaker->user_id) {
            throw new RuntimeException("{$speaker->name} already has a portal account.");
        }

        PortalInvitation::where('speaker_id', $speaker->id)->whereNull('accepted_at')->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        return $this->issueAndSend([
            'kind' => PortalInvitation::KIND_SPEAKER,
            'email' => strtolower(trim($speaker->contact_email)),
            'speaker_id' => $speaker->id,
        ], $invitedBy);
    }

    public function invitePartnerMember(Partner $partner, string $email, string $role, User $invitedBy): PortalInvitation
    {
        $email = strtolower(trim($email));
        $role = $role === 'owner' ? 'owner' : 'member';

        if ($partner->members()->where('email', $email)->exists()) {
            throw new RuntimeException("{$email} is already on {$partner->name}'s team.");
        }

        PortalInvitation::where('partner_id', $partner->id)->where('email', $email)
            ->whereNull('accepted_at')->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        return $this->issueAndSend([
            'kind' => PortalInvitation::KIND_PARTNER,
            'email' => $email,
            'partner_id' => $partner->id,
            'member_role' => $role,
        ], $invitedBy);
    }

    public function revoke(PortalInvitation $invitation): void
    {
        $invitation->forceFill(['revoked_at' => now()])->save();
        Audit::record('portal_invitation.revoked', subject: $invitation, after: ['email' => $invitation->email]);
    }

    /** A pending invitation, or 404 — never confirms that a token once existed. */
    public function resolve(string $token): PortalInvitation
    {
        $invitation = PortalInvitation::with(['speaker', 'partner'])
            ->where('token_hash', hash('sha256', $token))
            ->first();

        abort_unless($invitation && $invitation->isPending(), 404);
        abort_unless($invitation->speaker || $invitation->partner, 404);

        return $invitation;
    }

    public function existingUserFor(PortalInvitation $invitation): ?User
    {
        return User::where('email', $invitation->email)->first();
    }

    /**
     * Someone new: creates the account. Following the emailed link proves
     * the mailbox, so the address counts as verified (as on
     * ConfirmRegistrationController).
     */
    public function acceptAsNewUser(PortalInvitation $invitation, string $firstName, string $lastName, string $password): User
    {
        return DB::transaction(function () use ($invitation, $firstName, $lastName, $password) {
            if ($this->existingUserFor($invitation)) {
                throw ValidationException::withMessages(['email' => 'An account with this email already exists. Log in to accept the invitation.']);
            }

            $user = new User([
                'first_name' => $firstName,
                'last_name' => $lastName,
                'name' => trim("{$firstName} {$lastName}"),
                'email' => $invitation->email,
                'password' => Hash::make($password),
            ]);
            $user->save();
            $user->markEmailAsVerified();
            event(new Verified($user));

            $this->link($invitation, $user);

            return $user;
        });
    }

    /** An existing account, signed in as the invited address. */
    public function acceptAsExistingUser(PortalInvitation $invitation, User $user): void
    {
        if (strtolower($user->email) !== $invitation->email) {
            throw ValidationException::withMessages(['email' => 'This invitation was sent to a different email address.']);
        }

        DB::transaction(fn () => $this->link($invitation, $user));
    }

    private function link(PortalInvitation $invitation, User $user): void
    {
        // Single use: claimed with a conditional update so two tabs can't both accept.
        $claimed = PortalInvitation::whereKey($invitation->id)->whereNull('accepted_at')->whereNull('revoked_at')
            ->update(['accepted_at' => now(), 'accepted_by' => $user->id]);

        abort_unless($claimed === 1, 404);

        if ($invitation->kind === PortalInvitation::KIND_SPEAKER) {
            $speaker = Speaker::lockForUpdate()->findOrFail($invitation->speaker_id);

            if ($speaker->user_id && $speaker->user_id !== $user->id) {
                throw ValidationException::withMessages(['email' => 'This speaker profile is already linked to another account.']);
            }

            if (Speaker::where('user_id', $user->id)->whereKeyNot($speaker->id)->exists()) {
                throw ValidationException::withMessages(['email' => 'This account is already linked to another speaker profile.']);
            }

            $speaker->forceFill(['user_id' => $user->id])->save();
            Audit::record('portal_invitation.accepted', subject: $speaker, actor: $user, after: ['kind' => 'speaker']);

            return;
        }

        $partner = Partner::findOrFail($invitation->partner_id);
        $partner->members()->syncWithoutDetaching([$user->id => ['role' => $invitation->member_role ?: 'member']]);
        Audit::record('portal_invitation.accepted', subject: $partner, actor: $user, after: [
            'kind' => 'partner',
            'role' => $invitation->member_role,
        ]);
    }

    private function issueAndSend(array $attributes, User $invitedBy): PortalInvitation
    {
        $token = Str::random(48);

        $invitation = PortalInvitation::create([
            ...$attributes,
            'token_hash' => hash('sha256', $token),
            'invited_by' => $invitedBy->id,
            'expires_at' => now()->addDays(self::EXPIRY_DAYS),
        ]);
        $invitation->load(['speaker', 'partner']);

        try {
            Notification::route('mail', $invitation->email)->notifyNow(new PortalInvitationNotification(
                kind: $invitation->kind,
                subjectName: $invitation->subjectName(),
                inviterName: $invitedBy->name,
                url: route('portal-invitation.show', $token),
                expiresAt: $invitation->expires_at,
            ));
        } catch (Throwable $e) {
            $invitation->forceFill(['revoked_at' => now()])->save();
            Log::warning('portal_invitation.failed', ['invitation_id' => $invitation->id, 'error' => $e->getMessage()]);

            throw new RuntimeException('The email could not be sent, so no invitation was issued. Check the mail settings and try again.');
        }

        Audit::record('portal_invitation.sent', subject: $invitation, actor: $invitedBy, after: [
            'kind' => $invitation->kind,
            'to' => $invitation->email,
            'speaker_id' => $invitation->speaker_id,
            'partner_id' => $invitation->partner_id,
            'member_role' => $invitation->member_role,
        ]);

        return $invitation;
    }
}
