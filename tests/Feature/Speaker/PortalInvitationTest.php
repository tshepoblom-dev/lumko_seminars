<?php

namespace Tests\Feature\Speaker;

use App\Models\PortalInvitation;
use App\Models\Speaker;
use App\Models\User;
use App\Notifications\PortalInvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesEventWorld;
use Tests\TestCase;

/** Speaker portal invitations: admin invites, the speaker accepts with a new or an existing account. */
class PortalInvitationTest extends TestCase
{
    use CreatesEventWorld, RefreshDatabase;

    private function speaker(array $attributes = []): Speaker
    {
        return Speaker::factory()->create(['name' => 'Naledi Dube', 'contact_email' => 'naledi@example.com', ...$attributes]);
    }

    /** Invites as a super admin and returns the token from the email. */
    private function invite(Speaker $speaker): string
    {
        Notification::fake();

        $this->actingAs($this->makeSuperAdmin())
            ->post(route('admin.speakers.portal-invite', $speaker))
            ->assertSessionHas('success');

        $url = null;
        Notification::assertSentOnDemand(PortalInvitationNotification::class, function ($n, $channels, AnonymousNotifiable $notifiable) use (&$url) {
            $url = $n->url;

            return $notifiable->routes['mail'] === 'naledi@example.com';
        });

        $this->signOut();

        return basename(parse_url($url, PHP_URL_PATH));
    }

    private function signOut(): void
    {
        auth()->logout();
        $this->app['auth']->forgetGuards();
        Inertia::flushShared();
    }

    public function test_an_invitation_stores_only_the_token_hash_and_is_audited(): void
    {
        $speaker = $this->speaker();
        $token = $this->invite($speaker);

        $invitation = PortalInvitation::sole();
        $this->assertSame(hash('sha256', $token), $invitation->token_hash);
        $this->assertSame('speaker', $invitation->kind);
        $this->assertSame('naledi@example.com', $invitation->email);
        $this->assertSame('invited', $speaker->fresh()->portalStatus());
        $this->assertDatabaseHas('audit_logs', ['action' => 'portal_invitation.sent']);
    }

    public function test_a_speaker_without_a_contact_email_cannot_be_invited(): void
    {
        Notification::fake();
        $speaker = $this->speaker(['contact_email' => null]);

        $this->actingAs($this->makeSuperAdmin())
            ->post(route('admin.speakers.portal-invite', $speaker))
            ->assertSessionHas('error');

        Notification::assertNothingSent();
        $this->assertSame(0, PortalInvitation::count());
    }

    public function test_someone_new_creates_a_verified_account_linked_to_the_speaker(): void
    {
        $speaker = $this->speaker();
        $token = $this->invite($speaker);

        $this->get(route('portal-invitation.show', $token))
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Portal/AcceptInvitation')
                ->where('mode', 'new')
                ->where('email', 'naledi@example.com')
                ->where('suggestedName.first', 'Naledi')
                ->where('suggestedName.last', 'Dube'));

        $this->post(route('portal-invitation.store', $token), [
            'first_name' => 'Naledi',
            'last_name' => 'Dube',
            'password' => 'a-long-Passw0rd!',
            'password_confirmation' => 'a-long-Passw0rd!',
        ])->assertRedirect(route('speaker.dashboard'));

        $user = User::where('email', 'naledi@example.com')->sole();
        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertFalse($user->isStaff());
        $this->assertSame($user->id, $speaker->fresh()->user_id);
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull(PortalInvitation::sole()->accepted_at);
        $this->assertSame('active', $speaker->fresh()->portalStatus());
    }

    public function test_an_invitation_works_once(): void
    {
        $speaker = $this->speaker();
        $token = $this->invite($speaker);

        $this->post(route('portal-invitation.store', $token), [
            'first_name' => 'Naledi', 'last_name' => 'Dube',
            'password' => 'a-long-Passw0rd!', 'password_confirmation' => 'a-long-Passw0rd!',
        ]);
        $this->signOut();

        $this->get(route('portal-invitation.show', $token))->assertNotFound();
    }

    public function test_expired_unknown_and_replaced_invitations_are_404(): void
    {
        $speaker = $this->speaker();
        $first = $this->invite($speaker);
        $second = $this->invite($speaker);

        // A new invitation replaces the old one.
        $this->get(route('portal-invitation.show', $first))->assertNotFound();
        $this->get(route('portal-invitation.show', $second))->assertOk();
        $this->get(route('portal-invitation.show', 'not-a-real-token'))->assertNotFound();

        $this->travel(15)->days();
        $this->get(route('portal-invitation.show', $second))->assertNotFound();
    }

    public function test_an_existing_account_must_log_in_then_confirm(): void
    {
        $existing = User::factory()->create(['email' => 'naledi@example.com']);
        $speaker = $this->speaker();
        $token = $this->invite($speaker);

        $this->get(route('portal-invitation.show', $token))
            ->assertInertia(fn (Assert $page) => $page->where('mode', 'login'))
            ->assertSessionHas('url.intended', route('portal-invitation.show', $token));

        // Posting while signed out never creates or links anything.
        $this->post(route('portal-invitation.store', $token), [
            'first_name' => 'X', 'last_name' => 'Y',
            'password' => 'a-long-Passw0rd!', 'password_confirmation' => 'a-long-Passw0rd!',
        ])->assertRedirect(route('portal-invitation.show', $token));
        $this->assertSame(1, User::where('email', 'naledi@example.com')->count());
        $this->assertNull($speaker->fresh()->user_id);

        $this->actingAs($existing)
            ->get(route('portal-invitation.show', $token))
            ->assertInertia(fn (Assert $page) => $page->where('mode', 'confirm'));

        $this->actingAs($existing)
            ->post(route('portal-invitation.store', $token))
            ->assertRedirect(route('speaker.dashboard'));

        $this->assertSame($existing->id, $speaker->fresh()->user_id);
    }

    public function test_someone_signed_in_as_another_account_cannot_accept(): void
    {
        $speaker = $this->speaker();
        $token = $this->invite($speaker);
        $other = User::factory()->create(['email' => 'someone.else@example.com']);

        $this->actingAs($other)
            ->get(route('portal-invitation.show', $token))
            ->assertInertia(fn (Assert $page) => $page->where('mode', 'wrong_account'));

        $this->actingAs($other)->post(route('portal-invitation.store', $token));

        $this->assertNull($speaker->fresh()->user_id);
        $this->assertNull(PortalInvitation::sole()->accepted_at);
    }

    public function test_speakers_land_in_their_portal_after_login_and_staff_in_admin(): void
    {
        $user = User::factory()->create(['email' => 'naledi@example.com', 'password' => 'a-long-Passw0rd!']);
        $this->speaker(['user_id' => $user->id]);

        $this->post(route('login'), ['email' => 'naledi@example.com', 'password' => 'a-long-Passw0rd!'])
            ->assertRedirect(route('speaker.dashboard', absolute: false));

        $this->signOut();
        $admin = $this->makeSuperAdmin();
        $this->speaker(['user_id' => $admin->id, 'contact_email' => 'admin-speaker@example.com']);

        $this->post(route('login'), ['email' => $admin->email, 'password' => 'password'])
            ->assertRedirect(route('admin.dashboard', absolute: false));
    }

    public function test_staff_can_remove_portal_access_without_deleting_the_account(): void
    {
        $user = User::factory()->create();
        $speaker = $this->speaker(['user_id' => $user->id]);

        $this->actingAs($this->makeSuperAdmin())
            ->delete(route('admin.speakers.portal-revoke', $speaker))
            ->assertSessionHas('success');

        $this->assertNull($speaker->fresh()->user_id);
        $this->assertModelExists($user);

        $this->actingAs($user->fresh())->get(route('speaker.dashboard'))->assertForbidden();
    }

    public function test_invite_all_emails_only_the_events_speakers_not_yet_invited(): void
    {
        Notification::fake();
        $event = $this->makeEvent();
        $other = $this->makeEvent();
        $fresh = $this->speaker(['name' => 'Fresh Speaker', 'contact_email' => 'fresh@example.com']);
        $noEmail = $this->speaker(['name' => 'No Email', 'contact_email' => null]);
        $active = $this->speaker(['name' => 'Has Account', 'contact_email' => 'active@example.com', 'user_id' => User::factory()->create()->id]);
        $invited = $this->speaker(['name' => 'Already Invited', 'contact_email' => 'invited@example.com']);
        $elsewhere = $this->speaker(['name' => 'Other Event', 'contact_email' => 'elsewhere@example.com']);
        foreach ([$fresh, $noEmail, $active, $invited] as $i => $s) {
            $event->speakers()->attach($s->id, ['sort_order' => $i]);
        }
        $other->speakers()->attach($elsewhere->id, ['sort_order' => 0]);

        $admin = $this->makeSuperAdmin();
        app(\App\Services\PortalInvitationService::class)->inviteSpeaker($invited, $admin);
        Notification::fake(); // count only what invite-all sends

        $this->actingAs($admin)->post(route('admin.events.speakers.portal-invite-all', $event))
            ->assertSessionHas('success', 'Invited 1 speaker to the speaker portal.');

        Notification::assertSentOnDemandTimes(PortalInvitationNotification::class, 1);
        Notification::assertSentOnDemand(PortalInvitationNotification::class,
            fn ($n, $channels, AnonymousNotifiable $notifiable) => $notifiable->routes['mail'] === 'fresh@example.com');
        $this->assertDatabaseHas('audit_logs', ['action' => 'speaker.portal_invite_all']);

        // Nobody left to invite.
        $this->actingAs($admin)->post(route('admin.events.speakers.portal-invite-all', $event))->assertSessionHas('info');
    }

    public function test_invite_all_is_limited_to_staff_for_that_event(): void
    {
        Notification::fake();
        $event = $this->makeEvent();
        $speaker = $this->speaker();
        $event->speakers()->attach($speaker->id, ['sort_order' => 0]);

        $this->actingAs($this->makeManagerFor($this->makeEvent()))
            ->post(route('admin.events.speakers.portal-invite-all', $event))->assertForbidden();
        Notification::assertNothingSent();

        $this->actingAs($this->makeManagerFor($event))
            ->post(route('admin.events.speakers.portal-invite-all', $event))->assertSessionHas('success');
        Notification::assertSentOnDemandTimes(PortalInvitationNotification::class, 1);
    }

    public function test_creating_a_speaker_can_send_the_invitation_straight_away(): void
    {
        Notification::fake();
        $admin = $this->makeSuperAdmin();

        $this->actingAs($admin)->post(route('admin.speakers.store'), [
            'name' => 'Invited On Create', 'contact_email' => 'oncreate@example.com', 'invite_to_portal' => '1',
        ])->assertSessionHas('success', fn ($m) => str_contains($m, 'invitation emailed to oncreate@example.com'));

        $this->actingAs($admin)->post(route('admin.speakers.store'), [
            'name' => 'Not Invited', 'contact_email' => 'later@example.com', 'invite_to_portal' => '0',
        ]);

        Notification::assertSentOnDemandTimes(PortalInvitationNotification::class, 1);
        $this->assertSame('invited', Speaker::where('name', 'Invited On Create')->sole()->portalStatus());
        $this->assertSame('not_invited', Speaker::where('name', 'Not Invited')->sole()->portalStatus());
    }

    public function test_event_speakers_page_shows_each_speakers_portal_state(): void
    {
        Notification::fake();
        $event = $this->makeEvent();
        $speaker = $this->speaker();
        $event->speakers()->attach($speaker->id, ['sort_order' => 0]);
        $admin = $this->makeSuperAdmin();

        $this->actingAs($admin)->get(route('admin.events.speakers.index', $event))
            ->assertInertia(fn (Assert $page) => $page
                ->where('speakers.0.portal.status', 'not_invited')
                ->where('speakers.0.portal.can_invite', true)
                ->where('speakers.0.contact_email', 'naledi@example.com'));

        app(\App\Services\PortalInvitationService::class)->inviteSpeaker($speaker, $admin);

        $this->actingAs($admin)->get(route('admin.events.speakers.index', $event))
            ->assertInertia(fn (Assert $page) => $page
                ->where('speakers.0.portal.status', 'invited')
                ->where('speakers.0.portal.invited_to', 'naledi@example.com')
                ->where('speakers.0.portal.expires_on', now()->addDays(14)->format('j M Y')));

        $this->actingAs($admin)->get(route('admin.speakers.edit', $speaker))
            ->assertInertia(fn (Assert $page) => $page->where('portal.status', 'invited'));
    }

    public function test_attendees_cannot_invite(): void
    {
        $speaker = $this->speaker();
        $attendee = User::factory()->create();

        $this->actingAs($attendee)->post(route('admin.speakers.portal-invite', $speaker))->assertForbidden();
    }
}
