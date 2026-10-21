<?php

namespace Tests\Feature\Partner;

use App\Models\Partner;
use App\Models\PortalInvitation;
use App\Models\User;
use App\Notifications\PortalInvitationNotification;
use App\Services\PortalInvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\Concerns\CreatesEventWorld;
use Tests\Concerns\CreatesPartners;
use Tests\TestCase;

/** Staff's side of partner portal access: invite, resend, cancel, remove, bulk owner invites, and the staff view. */
class PartnerPortalAccessTest extends TestCase
{
    use CreatesEventWorld, CreatesPartners, RefreshDatabase;

    /** The token in the most recent invitation email to $email. */
    private function tokenSentTo(string $email): string
    {
        $url = null;
        Notification::assertSentOnDemand(PortalInvitationNotification::class, function ($n, $channels, AnonymousNotifiable $notifiable) use (&$url, $email) {
            if ($notifiable->routes['mail'] !== $email) {
                return false;
            }
            $url = $n->url;

            return true;
        });

        return basename(parse_url($url, PHP_URL_PATH));
    }

    public function test_staff_invite_resend_and_cancel_a_team_invitation(): void
    {
        Notification::fake();
        $partner = $this->partnerOn($this->makeEvent());
        $admin = $this->makeSuperAdmin();

        $this->actingAs($admin)->post(route('admin.partners.invitations.store', $partner), ['email' => 'Thandi@DataWorks.example', 'role' => 'owner'])
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'thandi@dataworks.example'));
        $first = PortalInvitation::sole();
        $this->assertSame('owner', $first->member_role);
        $firstToken = $this->tokenSentTo('thandi@dataworks.example');

        Notification::fake();
        $this->actingAs($admin)->post(route('admin.partners.invitations.resend', [$partner, $first]))
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'earlier link no longer works'));
        $secondToken = $this->tokenSentTo('thandi@dataworks.example');

        $this->assertNotNull($first->fresh()->revoked_at);
        $second = PortalInvitation::whereNull('revoked_at')->sole();
        $this->assertSame('owner', $second->member_role);

        auth()->logout();
        $this->get(route('portal-invitation.show', $firstToken))->assertNotFound();
        $this->get(route('portal-invitation.show', $secondToken))->assertOk();

        // A revoked invitation can't be resent or cancelled again.
        $this->actingAs($admin)->post(route('admin.partners.invitations.resend', [$partner, $first]))->assertNotFound();

        $this->actingAs($admin)->delete(route('admin.partners.invitations.cancel', [$partner, $second]))->assertSessionHas('success');
        $this->assertNotNull($second->fresh()->revoked_at);
        auth()->logout();
        $this->get(route('portal-invitation.show', $secondToken))->assertNotFound();
        $this->assertSame('none', $partner->fresh()->portalSummary()['status']);
    }

    public function test_an_invitation_from_another_partner_is_404(): void
    {
        $event = $this->makeEvent();
        $mine = $this->partnerOn($event);
        $theirs = $this->partnerOn($event, attributes: ['name' => 'Other Co']);
        $admin = $this->makeSuperAdmin();

        Notification::fake();
        $invitation = app(PortalInvitationService::class)->invitePartnerMember($theirs, 'x@other.example', 'member', $admin);

        $this->actingAs($admin)->post(route('admin.partners.invitations.resend', [$mine, $invitation]))->assertNotFound();
        $this->actingAs($admin)->delete(route('admin.partners.invitations.cancel', [$mine, $invitation]))->assertNotFound();
        $this->assertNull($invitation->fresh()->revoked_at);
    }

    public function test_someone_already_on_the_team_is_not_invited_again(): void
    {
        Notification::fake();
        $partner = $this->partnerOn($this->makeEvent());
        $this->partnerMember($partner, 'owner', ['email' => 'owner@dataworks.example']);

        $this->actingAs($this->makeSuperAdmin())
            ->post(route('admin.partners.invitations.store', $partner), ['email' => 'owner@dataworks.example', 'role' => 'member'])
            ->assertSessionHas('error');
        Notification::assertNothingSent();
    }

    public function test_staff_remove_a_member_without_deleting_the_account(): void
    {
        $partner = $this->partnerOn($this->makeEvent());
        $member = $this->partnerMember($partner, 'owner');
        $stranger = User::factory()->create();
        $admin = $this->makeSuperAdmin();

        $this->actingAs($admin)->delete(route('admin.partners.members.remove', [$partner, $stranger]))->assertNotFound();

        $this->actingAs($admin)->delete(route('admin.partners.members.remove', [$partner, $member]))->assertSessionHas('success');
        $this->assertFalse($partner->fresh()->hasMember($member));
        $this->assertModelExists($member);
        $this->assertDatabaseHas('audit_logs', ['action' => 'partner.member_removed']);

        $this->actingAs($member->fresh())->get(route('partner.dashboard', $partner))->assertForbidden();
    }

    public function test_any_staff_member_manages_the_library_but_bulk_invites_are_per_event(): void
    {
        Notification::fake();
        $event = $this->makeEvent();
        $partner = $this->partnerOn($event, attributes: ['contact_email' => 'hello@dataworks.example']);
        $manager = $this->makeManagerFor($this->makeEvent());

        $this->actingAs($manager)->post(route('admin.partners.invitations.store', $partner), ['email' => 'a@dataworks.example', 'role' => 'member'])
            ->assertSessionHas('success');

        $this->actingAs($manager)->post(route('admin.events.partners.invite-owners', $event))->assertForbidden();

        $attendee = User::factory()->create();
        $this->actingAs($attendee)->post(route('admin.partners.invitations.store', $partner), ['email' => 'b@dataworks.example', 'role' => 'member'])
            ->assertForbidden();

        Notification::assertSentOnDemandTimes(PortalInvitationNotification::class, 1);
    }

    public function test_bulk_invites_owners_only_for_this_events_partners_without_a_team_or_invitation(): void
    {
        Notification::fake();
        $event = $this->makeEvent();
        $admin = $this->makeSuperAdmin();

        $fresh = $this->partnerOn($event, attributes: ['name' => 'Fresh Co', 'contact_email' => 'fresh@fresh.example']);
        $this->partnerOn($event, attributes: ['name' => 'No Email Co', 'contact_email' => null]);
        $withTeam = $this->partnerOn($event, attributes: ['name' => 'Team Co', 'contact_email' => 'team@team.example']);
        $this->partnerMember($withTeam, 'owner');
        $invited = $this->partnerOn($event, attributes: ['name' => 'Invited Co', 'contact_email' => 'invited@invited.example']);
        app(PortalInvitationService::class)->invitePartnerMember($invited, 'someone@invited.example', 'owner', $admin);
        $this->partnerOn($this->makeEvent(), attributes: ['name' => 'Elsewhere Co', 'contact_email' => 'elsewhere@else.example']);

        $this->actingAs($admin)->get(route('admin.events.partners.index', $event))
            ->assertInertia(fn (Assert $page) => $page
                ->where('partners', fn ($rows) => collect($rows)->firstWhere('name', 'Fresh Co')['portal']['can_invite_owner'] === true
                    && collect($rows)->firstWhere('name', 'No Email Co')['portal']['can_invite_owner'] === false
                    && collect($rows)->firstWhere('name', 'Team Co')['portal']['status'] === 'active'
                    && collect($rows)->firstWhere('name', 'Invited Co')['portal']['status'] === 'invited'));

        Notification::fake(); // count only what the bulk invite sends
        $this->actingAs($admin)->post(route('admin.events.partners.invite-owners', $event))
            ->assertSessionHas('success', 'Invited the owners of 1 partner to their portal.');

        Notification::assertSentOnDemandTimes(PortalInvitationNotification::class, 1);
        $this->tokenSentTo('fresh@fresh.example');
        $this->assertSame('owner', PortalInvitation::where('partner_id', $fresh->id)->sole()->member_role);
        $this->assertDatabaseHas('audit_logs', ['action' => 'partner.portal_invite_owners']);

        // Nobody left to invite.
        $this->actingAs($admin)->post(route('admin.events.partners.invite-owners', $event))->assertSessionHas('info');
    }

    public function test_bulk_invites_carry_on_past_a_failure_and_name_it(): void
    {
        $event = $this->makeEvent();
        $this->partnerOn($event, attributes: ['name' => 'Works Co', 'contact_email' => 'ok@works.example']);
        $this->partnerOn($event, attributes: ['name' => 'Broken Co', 'contact_email' => 'bounce@broken.example']);

        $this->mock(PortalInvitationService::class, fn ($mock) => $mock->shouldReceive('invitePartnerMember')->twice()
            ->andReturnUsing(fn (Partner $p, string $email) => $email === 'bounce@broken.example'
                ? throw new RuntimeException('The email could not be sent.')
                : new PortalInvitation));

        $this->actingAs($this->makeSuperAdmin())->post(route('admin.events.partners.invite-owners', $event))
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'Invited the owners of 1 partner')
                && str_contains($m, "1 couldn't be sent: Broken Co"));
    }

    public function test_creating_a_partner_can_invite_its_main_contact_as_owner(): void
    {
        Notification::fake();
        $admin = $this->makeSuperAdmin();

        $this->actingAs($admin)->post(route('admin.partners.store'), [
            'name' => 'Invited Co', 'contact_email' => 'boss@invited.example', 'invite_owner' => '1',
        ])->assertSessionHas('success', fn ($m) => str_contains($m, 'Portal invitation emailed to boss@invited.example as owner'));

        $this->actingAs($admin)->post(route('admin.partners.store'), [
            'name' => 'Later Co', 'contact_email' => 'later@later.example', 'invite_owner' => '0',
        ]);
        $this->actingAs($admin)->post(route('admin.partners.store'), ['name' => 'No Email Co', 'invite_owner' => '1']);

        Notification::assertSentOnDemandTimes(PortalInvitationNotification::class, 1);
        $this->assertSame('owner', PortalInvitation::sole()->member_role);
        $this->assertSame('invited', Partner::where('name', 'Invited Co')->sole()->portalSummary()['status']);
        $this->assertSame('none', Partner::where('name', 'Later Co')->sole()->portalSummary()['status']);
        $this->assertSame(3, Partner::count());
    }

    public function test_the_edit_and_library_pages_show_the_team_and_pending_invitations(): void
    {
        Notification::fake();
        $partner = $this->partnerOn($this->makeEvent());
        $owner = $this->partnerMember($partner, 'owner', ['name' => 'Ayanda Owner', 'email' => 'ayanda@dataworks.example']);
        $admin = $this->makeSuperAdmin();
        app(PortalInvitationService::class)->invitePartnerMember($partner, 'new@dataworks.example', 'member', $admin);

        $this->actingAs($admin)->get(route('admin.partners.edit', $partner))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Partners/Form')
                ->where('portal.status', 'active')
                ->where('portal.members.0.id', $owner->id)
                ->where('portal.members.0.role', 'owner')
                ->where('portal.pending.0.email', 'new@dataworks.example')
                ->where('portal.pending.0.role', 'member')
                ->where('portal.pending.0.expires_on', now()->addDays(14)->format('j M Y'))
                ->has('partner.conversions'));

        $this->actingAs($admin)->get(route('admin.partners.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('partners.0.portal.status', 'active')
                ->has('partners.0.portal.members', 1)
                ->has('partners.0.portal.pending', 1));
    }

    public function test_super_admins_open_any_partners_portal_as_staff_and_event_managers_cannot(): void
    {
        $event = $this->makeEvent();
        $partner = $this->partnerOn($event);
        $member = $this->partnerMember($partner);

        // Not on the team, so the portal shows the staff-view bar.
        $this->actingAs($this->makeSuperAdmin())->get(route('partner.dashboard', $partner))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('auth.portals.partners', []));

        $this->actingAs($member)->get(route('partner.dashboard', $partner))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('auth.portals.partners.0.slug', $partner->slug));

        $this->actingAs($this->makeManagerFor($event))->get(route('partner.dashboard', $partner))->assertForbidden();
    }
}
