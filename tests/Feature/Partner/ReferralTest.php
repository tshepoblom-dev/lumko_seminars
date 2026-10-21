<?php

namespace Tests\Feature\Partner;

use App\Models\ClientConversion;
use App\Models\ConsentRecord;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\PartnerConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesEventWorld;
use Tests\Concerns\CreatesPartners;
use Tests\TestCase;

/**
 * "How did you hear about us?" naming a partner is attribution only; the
 * separate tickbox is consent. Staff record clients and credit partners;
 * partners only see counts.
 */
class ReferralTest extends TestCase
{
    use CreatesEventWorld, CreatesPartners, RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return [
            'first_name' => 'Thabo',
            'last_name' => 'Mokoena',
            'email' => 'thabo@example.com',
            'organisation' => 'ABC Municipality',
            'job_title' => 'IT Manager',
            'attendance_mode' => 'online',
            'networking_consent' => false,
            'lumko_marketing_consent' => false,
            'accept_legal' => true,
            ...$overrides,
        ];
    }

    private function event(): Event
    {
        return $this->makeEvent(['starts_at' => now()->addWeek(), 'ends_at' => now()->addWeek()->addHours(3)]);
    }

    public function test_the_form_offers_the_events_referral_partners_and_a_referral_link_preselects_one(): void
    {
        Storage::fake('public');
        $event = $this->event();
        $partner = $this->partnerOn($event, attributes: ['logo_path' => 'partners/dw.png', 'logo_approved' => true, 'logo_alt' => 'DataWorks logo']);
        // Every partner published on the event is offered, whatever its tier;
        // only tiers that take leads get the "share my details" box.
        $this->partnerOn($event, ['listing'], ['name' => 'Listing Only Co']);
        $this->partnerOn($event, attributes: ['name' => 'Hidden Co'], published: false);

        $this->get(route('event.register', ['ref' => $partner->slug]))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Public/Register')
                ->has('referralPartners', 2)
                ->where('referralPartners.0.name', 'DataWorks')
                ->where('referralPartners.0.can_share', true)
                ->where('referralPartners.0.logo_url', fn ($url) => str_ends_with($url, 'partners/dw.png'))
                ->where('referralPartners.0.logo_alt', 'DataWorks logo')
                ->where('referralPartners.1.name', 'Listing Only Co')
                ->where('referralPartners.1.can_share', false)
                ->where('referralPartners.1.logo_url', null)
                ->where('prefillReferralPartnerId', $partner->id)
                ->has('referralSources'));
    }

    public function test_naming_a_partner_records_the_referral_but_shares_nothing(): void
    {
        Notification::fake();
        $event = $this->event();
        $partner = $this->partnerOn($event);

        $this->post(route('event.register.store'), $this->payload(['referral_source' => 'partner', 'referral_partner_id' => $partner->id]))
            ->assertRedirect(route('event.register.confirmation'));

        $registration = EventRegistration::sole();
        $this->assertSame('partner', $registration->referral_source);
        $this->assertSame($partner->id, $registration->referred_by_partner_id);
        $this->assertSame(0, ConsentRecord::where('type', 'partner_contact')->count());
        $this->assertSame(0, PartnerConnection::count());
    }

    public function test_ticking_the_box_shares_details_once_the_email_is_confirmed(): void
    {
        Notification::fake();
        $event = $this->event();
        $partner = $this->partnerOn($event);
        $member = $this->partnerMember($partner);

        $this->post(route('event.register.store'), $this->payload([
            'referral_source' => 'partner', 'referral_partner_id' => $partner->id, 'share_with_partner' => true,
        ]));

        $record = ConsentRecord::where('type', 'partner_contact')->sole();
        $this->assertNull($record->effective_at);
        $this->assertSame('registration', $record->source);
        $this->assertSame($partner->id, $record->partner_id);
        $this->assertSame(0, PartnerConnection::active()->count());

        $user = User::where('email', 'thabo@example.com')->sole();
        $token = Password::broker()->createToken($user);
        $this->post(route('registration.confirm.store'), [
            'token' => $token, 'email' => 'thabo@example.com',
            'password' => 'a-long-Passw0rd!', 'password_confirmation' => 'a-long-Passw0rd!',
        ]);

        $lead = PartnerConnection::sole();
        $this->assertTrue($lead->isActive());
        $this->assertSame('registration', $lead->source);

        auth()->logout();
        $this->actingAs($member)->get(route('partner.events.leads.index', [$partner, $event]))
            ->assertInertia(fn (Assert $page) => $page->has('leads', 1)->where('leads.0.source', 'registration'));
    }

    public function test_only_this_events_referral_partners_can_be_named(): void
    {
        Notification::fake();
        $event = $this->event();
        $elsewhere = $this->makeEvent(['starts_at' => now()->addMonth(), 'ends_at' => now()->addMonth()->addHours(2)]);
        $otherEventPartner = $this->partnerOn($elsewhere, attributes: ['name' => 'Elsewhere Co']);
        $unpublished = $this->partnerOn($event, attributes: ['name' => 'Hidden Co'], published: false);

        foreach ([$otherEventPartner, $unpublished] as $partner) {
            $this->post(route('event.register.store'), $this->payload(['referral_source' => 'partner', 'referral_partner_id' => $partner->id]))
                ->assertSessionHasErrors('referral_partner_id');

            $this->postJson(route('event.register.validate-step'), ['step' => 2, ...$this->payload(['referral_source' => 'partner', 'referral_partner_id' => $partner->id])])
                ->assertStatus(422)
                ->assertJsonValidationErrors('referral_partner_id');
        }

        $this->assertSame(0, EventRegistration::count());
    }

    public function test_staff_record_a_client_crediting_the_partner_and_the_partner_sees_counts_only(): void
    {
        $event = $this->event();
        $partner = $this->partnerOn($event);
        $member = $this->partnerMember($partner);
        $registration = $this->registerAttendee($event, 'online', ['name' => 'Thabo Mokoena']);
        $registration->update(['referral_source' => 'partner', 'referred_by_partner_id' => $partner->id]);
        $this->registerAttendee($event); // not referred

        $admin = $this->makeSuperAdmin();
        $this->actingAs($admin)->get(route('admin.attendees.show', [$event, $registration]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('referral.label', 'DataWorks')
                ->where('suggestedPartnerId', $partner->id)
                ->where('conversion', null));

        $this->actingAs($admin)->put(route('admin.attendees.conversion.update', [$event, $registration]), [
            'converted_on' => now()->toDateString(), 'partner_id' => $partner->id, 'deal_value' => 25000,
        ])->assertSessionHas('success');

        $conversion = ClientConversion::sole();
        $this->assertSame($partner->id, $conversion->partner_id);
        $this->assertSame(2_500_000, $conversion->deal_value_cents);
        $this->assertDatabaseHas('audit_logs', ['action' => 'client_conversion.recorded']);

        // The partner is someone else, in their own session (not the admin's flash message).
        $this->flushSession();
        Inertia::flushShared();

        $response = $this->actingAs($member)->get(route('partner.events.referrals', [$partner, $event]))->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Partner/Referrals')
            ->where('counts.referred', 1)
            ->where('counts.converted', 1)
            ->missing('counts.deal_value_cents')
            ->where('link', route('event.register', ['ref' => $partner->slug])));
        $this->assertStringNotContainsString('Thabo', $response->getContent());

        $this->actingAs($admin)->get(route('admin.events.partners.index', $event))
            ->assertInertia(fn (Assert $page) => $page->where('referrals.0.converted', 1)->where('referrals.0.deal_value_cents', 2_500_000));
    }

    public function test_only_staff_for_the_event_can_record_clients(): void
    {
        $event = $this->event();
        $registration = $this->registerAttendee($event);
        $data = ['converted_on' => now()->toDateString()];

        $this->actingAs($registration->user)->put(route('admin.attendees.conversion.update', [$event, $registration]), $data)->assertForbidden();
        $this->actingAs($this->makeManagerFor($this->makeEvent()))->put(route('admin.attendees.conversion.update', [$event, $registration]), $data)->assertForbidden();
        $this->assertSame(0, ClientConversion::count());

        $this->actingAs($this->makeManagerFor($event))->put(route('admin.attendees.conversion.update', [$event, $registration]), $data)->assertSessionHas('success');
        $this->assertSame(1, ClientConversion::count());
    }
}
