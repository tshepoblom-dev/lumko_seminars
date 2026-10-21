<?php

namespace Tests\Feature\Partner;

use App\Models\EventPartner;
use App\Models\Partner;
use App\Models\PartnerTier;
use App\Models\PortalInvitation;
use App\Models\Resource;
use App\Models\User;
use App\Models\Voucher;
use App\Notifications\PortalInvitationNotification;
use App\Notifications\VoucherInvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesEventWorld;
use Tests\Concerns\CreatesPartners;
use Tests\TestCase;

/** Staff set partners up per event; partners manage their profile, team, resources and tickets. */
class PartnerAdminAndPortalTest extends TestCase
{
    use CreatesEventWorld, CreatesPartners, RefreshDatabase;

    public function test_staff_build_tiers_take_a_partner_on_and_publish_it(): void
    {
        $event = $this->makeEvent();
        $admin = $this->makeSuperAdmin();

        $this->actingAs($admin)->post(route('admin.partners.store'), ['name' => 'DataWorks', 'website' => 'https://dataworks.example'])
            ->assertRedirect(route('admin.partners.index'));
        $partner = Partner::sole();
        $this->assertSame('dataworks', $partner->slug);

        $this->actingAs($admin)->post(route('admin.events.partner-tiers.store', $event), [
            'name' => 'Gold', 'features' => ['listing', 'leads_connect', 'comp_tickets'], 'comp_tickets' => 3,
        ])->assertSessionHas('success');
        $tier = PartnerTier::sole();
        $this->assertSame(['listing', 'leads_connect', 'comp_tickets'], $tier->features);

        $this->actingAs($admin)->post(route('admin.events.partners.attach', $event), [
            'partner_id' => $partner->id, 'partner_tier_id' => $tier->id,
        ])->assertSessionHas('success');
        $eventPartner = EventPartner::sole();
        $this->assertFalse($eventPartner->is_published);

        $this->actingAs($admin)->patch(route('admin.events.partners.update', [$event, $eventPartner]), ['is_published' => true]);
        $this->assertTrue($eventPartner->fresh()->is_published);

        $this->actingAs($admin)->get(route('admin.events.partners.index', $event))
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Events/Partners')->has('partners', 1)->has('tiers', 1));

        // Unknown features are refused.
        $this->actingAs($admin)->post(route('admin.events.partner-tiers.store', $event), ['name' => 'Bad', 'features' => ['everything']])
            ->assertSessionHasErrors('features.0');
    }

    public function test_an_event_manager_cannot_change_another_events_partners(): void
    {
        $event = $this->makeEvent();
        $other = $this->makeEvent();
        $manager = $this->makeManagerFor($other);
        $partner = Partner::factory()->create();

        $this->actingAs($manager)->post(route('admin.events.partners.attach', $event), ['partner_id' => $partner->id])->assertForbidden();
        $this->actingAs($manager)->post(route('admin.events.partner-tiers.store', $event), ['name' => 'Gold'])->assertForbidden();
        $this->assertSame(0, EventPartner::count());
    }

    public function test_only_listed_published_partners_appear_on_the_site_and_logos_only_once_approved(): void
    {
        Storage::fake('public');
        $event = $this->makeEvent();
        $this->partnerOn($event, ['listing'], ['name' => 'Listed Co', 'logo_path' => 'partners/l.png', 'logo_approved' => false]);
        $this->partnerOn($event, ['profile_page'], ['name' => 'Not Listed Co']);
        $this->partnerOn($event, ['listing'], ['name' => 'Hidden Co'], published: false);

        $this->get(route('home'))->assertInertia(fn (Assert $page) => $page
            ->has('event.partners', 1)
            ->has('event.partners.0.partners', 1)
            ->where('event.partners.0.partners.0.name', 'Listed Co')
            ->where('event.partners.0.partners.0.logo_url', null));
    }

    public function test_inviting_a_partner_owner_who_accepts_lands_in_the_partner_portal(): void
    {
        Notification::fake();
        $event = $this->makeEvent();
        $partner = $this->partnerOn($event);
        $eventPartner = EventPartner::sole();

        $this->actingAs($this->makeSuperAdmin())
            ->post(route('admin.events.partners.invite', [$event, $eventPartner]), ['email' => 'Lerato@DataWorks.example', 'role' => 'owner'])
            ->assertSessionHas('success');

        $url = null;
        Notification::assertSentOnDemand(PortalInvitationNotification::class, function ($n, $channels, AnonymousNotifiable $notifiable) use (&$url) {
            $url = $n->url;

            return $notifiable->routes['mail'] === 'lerato@dataworks.example';
        });
        auth()->logout();
        Inertia::flushShared();

        $token = basename(parse_url($url, PHP_URL_PATH));
        $this->post(route('portal-invitation.store', $token), [
            'first_name' => 'Lerato', 'last_name' => 'Nkosi',
            'password' => 'a-long-Passw0rd!', 'password_confirmation' => 'a-long-Passw0rd!',
        ])->assertRedirect(route('partner.dashboard', $partner));

        $user = User::where('email', 'lerato@dataworks.example')->sole();
        $this->assertTrue($partner->isOwner($user));
        $this->assertSame('partner', PortalInvitation::sole()->kind);
    }

    public function test_owners_manage_the_team_and_members_cannot(): void
    {
        Notification::fake();
        $event = $this->makeEvent();
        $partner = $this->partnerOn($event);
        $owner = $this->partnerMember($partner, 'owner');
        $member = $this->partnerMember($partner);

        $this->actingAs($member)->post(route('partner.team.invite', $partner), ['email' => 'x@example.com', 'role' => 'member'])->assertForbidden();
        $this->actingAs($member)->delete(route('partner.team.remove', [$partner, $owner]))->assertForbidden();

        $this->actingAs($owner)->post(route('partner.team.invite', $partner), ['email' => 'rep@example.com', 'role' => 'member'])
            ->assertSessionHas('success');
        Notification::assertSentOnDemand(PortalInvitationNotification::class);

        // The last owner stays.
        $this->actingAs($owner)->delete(route('partner.team.remove', [$partner, $owner]))->assertSessionHas('error');
        $this->assertTrue($partner->hasMember($owner));

        $this->actingAs($owner)->delete(route('partner.team.remove', [$partner, $member]))->assertSessionHas('success');
        $this->assertFalse($partner->hasMember($member));
        $this->actingAs($member->fresh())->get(route('partner.dashboard', $partner))->assertForbidden();
    }

    public function test_complimentary_tickets_are_locked_vouchers_capped_by_the_tier(): void
    {
        Notification::fake();
        $event = $this->makeEvent(['in_person_price' => 500]);
        $partner = $this->partnerOn($event); // the factory tier allows 2
        $member = $this->partnerMember($partner);
        $route = route('partner.events.tickets.store', [$partner, $event]);

        $this->actingAs($member)->post($route, ['recipient_name' => 'Guest One', 'recipient_email' => 'one@example.com'])->assertSessionHas('success');
        $this->actingAs($member)->post($route, ['recipient_name' => 'Guest Two', 'recipient_email' => 'two@example.com'])->assertSessionHas('success');
        $this->actingAs($member)->post($route, ['recipient_name' => 'Guest Three', 'recipient_email' => 'three@example.com'])->assertSessionHas('error');

        $this->assertSame(2, Voucher::where('partner_id', $partner->id)->count());
        $voucher = Voucher::where('recipient_email', 'one@example.com')->sole();
        $this->assertTrue($voucher->email_locked);
        $this->assertNotNull($voucher->sent_at);
        Notification::assertSentOnDemandTimes(VoucherInvitationNotification::class, 2);
    }

    public function test_partner_resources_publish_without_emailing_attendees_and_show_on_its_page(): void
    {
        Storage::fake('local');
        Notification::fake();
        $event = $this->makeEvent();
        $partner = $this->partnerOn($event);
        $member = $this->partnerMember($partner);
        $attendee = $this->registerAttendee($event)->user;

        $this->actingAs($member)->post(route('partner.events.resources.store', [$partner, $event]), [
            'title' => 'Case study',
            'file' => UploadedFile::fake()->create('case.pdf', 50, 'application/pdf'),
        ])->assertSessionHas('success');

        $resource = Resource::sole();
        $this->assertSame($partner->id, $resource->partner_id);
        $this->assertTrue($resource->is_published);

        $this->artisan('resources:release')->assertSuccessful();
        Notification::assertNothingSentTo($attendee);

        $this->actingAs($attendee)->get(route('partners.show', [$event, $partner]))
            ->assertInertia(fn (Assert $page) => $page->has('resources', 1)->where('resources.0.title', 'Case study'));
        $this->actingAs($attendee)->get(route('resources.index'))
            ->assertInertia(fn (Assert $page) => $page->where('resources.0.shared_by', 'DataWorks'));
    }

    public function test_a_partners_new_logo_waits_for_approval(): void
    {
        Storage::fake('public');
        $event = $this->makeEvent();
        $partner = $this->partnerOn($event, attributes: ['logo_path' => 'partners/old.png', 'logo_approved' => true]);
        $member = $this->partnerMember($partner);

        $this->actingAs($member)->post(route('partner.profile.update', $partner), [
            'description' => 'We help municipalities use data.',
            'logo' => UploadedFile::fake()->image('logo.png'),
            'logo_alt' => 'DataWorks',
        ])->assertSessionHas('success');

        $partner->refresh();
        $this->assertFalse($partner->logo_approved);
        $this->assertNull($partner->publicLogoUrl());
        $this->assertSame('We help municipalities use data.', $partner->description);
    }

    public function test_analytics_are_aggregate_only(): void
    {
        $event = $this->makeEvent();
        $partner = $this->partnerOn($event);
        $member = $this->partnerMember($partner);
        $attendee = $this->registerAttendee($event, 'online', ['name' => 'Thabo Mokoena'])->user;
        $this->actingAs($attendee)->post(route('partners.connect', [$event, $partner]));

        $response = $this->actingAs($member)->get(route('partner.events.analytics', [$partner, $event]))->assertOk();
        $response->assertInertia(fn (Assert $page) => $page->component('Partner/Analytics')->where('totals.leads', 1)->has('daily', 30));
        $this->assertStringNotContainsString('Thabo', $response->getContent());
        $this->assertStringNotContainsString($attendee->email, $response->getContent());
    }
}
