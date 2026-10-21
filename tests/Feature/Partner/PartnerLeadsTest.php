<?php

namespace Tests\Feature\Partner;

use App\Actions\AccountAnonymiser;
use App\Enums\ConsentType;
use App\Models\ConsentRecord;
use App\Models\PartnerConnection;
use App\Models\PartnerLeadNote;
use App\Models\Ticket;
use App\Notifications\PartnerEnquiryReceived;
use App\Services\ConsentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesEventWorld;
use Tests\Concerns\CreatesPartners;
use Tests\TestCase;

/**
 * Partner leads are consent-only: Connect, an enquiry, or a stand scan the
 * attendee confirms. The partner sees exactly what the sentence names, and
 * a withdrawal removes the lead at once.
 */
class PartnerLeadsTest extends TestCase
{
    use CreatesEventWorld, CreatesPartners, RefreshDatabase;

    public function test_connecting_records_the_exact_consent_and_shows_the_lead_in_the_portal(): void
    {
        $event = $this->makeEvent();
        $partner = $this->partnerOn($event);
        $member = $this->partnerMember($partner);
        $attendee = $this->registerAttendee($event, 'online', ['name' => 'Thabo Mokoena', 'organisation' => 'City of Joburg'])->user;

        $this->actingAs($attendee)->get(route('partners.show', [$event, $partner]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Public/Partner')
                ->where('canConnect', true)
                ->where('connected', null)
                ->where('consentText', fn ($text) => str_contains($text, 'with DataWorks, a partner of this event')));

        $this->actingAs($attendee)
            ->post(route('partners.connect', [$event, $partner]), ['note' => 'Interested in your data platform'])
            ->assertSessionHas('success');

        $record = ConsentRecord::sole();
        $this->assertSame('partner_contact', $record->type);
        $this->assertSame($partner->id, $record->partner_id);
        $this->assertSame('connect', $record->source);
        $this->assertNotNull($record->effective_at);
        $this->assertStringNotContainsString(':partner', $record->purpose_text);

        $lead = PartnerConnection::sole();
        $this->assertSame('connect', $lead->source);
        $this->assertSame('Interested in your data platform', $lead->note);

        $this->actingAs($member)->get(route('partner.events.leads.index', [$partner, $event]))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Partner/Leads')
                ->has('leads', 1)
                ->where('leads.0.name', 'Thabo Mokoena')
                ->where('leads.0.email', $attendee->email)
                ->where('leads.0.organisation', 'City of Joburg')
                ->missing('leads.0.mobile'));

        $this->assertDatabaseHas('audit_logs', ['action' => 'partner.leads_viewed', 'subject_id' => $partner->id]);
    }

    public function test_an_enquiry_needs_the_consent_tick_and_tells_the_team_without_personal_data(): void
    {
        Notification::fake();
        $event = $this->makeEvent();
        $partner = $this->partnerOn($event);
        $member = $this->partnerMember($partner);
        $attendee = $this->registerAttendee($event, 'online', ['name' => 'Thabo Mokoena'])->user;

        $this->actingAs($attendee)
            ->post(route('partners.enquire', [$event, $partner]), ['message' => 'Do you work with municipalities?'])
            ->assertSessionHasErrors('consent');
        $this->assertSame(0, ConsentRecord::count());

        $this->actingAs($attendee)
            ->post(route('partners.enquire', [$event, $partner]), ['message' => 'Do you work with municipalities?', 'consent' => true])
            ->assertSessionHas('success');

        $lead = PartnerConnection::sole();
        $this->assertSame('enquiry', $lead->source);
        $this->assertSame('Do you work with municipalities?', $lead->note);

        Notification::assertSentTo($member, PartnerEnquiryReceived::class, function (PartnerEnquiryReceived $n) use ($member, $attendee) {
            $mail = $n->toMail($member)->render();

            return ! str_contains($mail, 'Thabo') && ! str_contains($mail, $attendee->email) && ! str_contains($mail, 'municipalities');
        });
    }

    public function test_tier_features_gate_the_attendee_actions_and_the_portal_pages(): void
    {
        $event = $this->makeEvent();
        // A listing-only tier: no partner page, no leads, no scanning.
        $partner = $this->partnerOn($event, ['listing']);
        $member = $this->partnerMember($partner);
        $attendee = $this->registerAttendee($event)->user;

        $this->actingAs($attendee)->get(route('partners.show', [$event, $partner]))->assertNotFound();
        $this->actingAs($attendee)->post(route('partners.connect', [$event, $partner]))->assertNotFound();
        $this->actingAs($attendee)->post(route('partners.enquire', [$event, $partner]), ['message' => 'x', 'consent' => true])->assertNotFound();

        $this->actingAs($member)->get(route('partner.events.scan.show', [$partner, $event]))->assertForbidden();
        $this->actingAs($member)->get(route('partner.events.analytics', [$partner, $event]))->assertForbidden();
        $this->actingAs($member)->get(route('partner.events.tickets.index', [$partner, $event]))->assertForbidden();
        // Leads always open (it may simply be empty).
        $this->actingAs($member)->get(route('partner.events.leads.index', [$partner, $event]))->assertOk();
        $this->assertSame(0, ConsentRecord::count());
    }

    public function test_an_unpublished_partner_has_no_page_and_staff_or_its_own_team_cannot_become_leads(): void
    {
        $event = $this->makeEvent();
        $hidden = $this->partnerOn($event, published: false, attributes: ['name' => 'Hidden Co']);
        $partner = $this->partnerOn($event, attributes: ['name' => 'Visible Co']);
        $attendee = $this->registerAttendee($event)->user;

        $this->actingAs($attendee)->get(route('partners.show', [$event, $hidden]))->assertNotFound();

        $this->actingAs($this->makeSuperAdmin())->post(route('partners.connect', [$event, $partner]))->assertForbidden();

        $teamMember = $this->partnerMember($partner);
        \App\Models\EventRegistration::factory()->create(['event_id' => $event->id, 'user_id' => $teamMember->id]);
        $this->actingAs($teamMember)->post(route('partners.connect', [$event, $partner]))->assertForbidden();

        $this->assertSame(0, PartnerConnection::count());
    }

    public function test_withdrawing_removes_the_lead_from_the_portal_at_once(): void
    {
        $event = $this->makeEvent();
        $partner = $this->partnerOn($event);
        $member = $this->partnerMember($partner);
        $attendee = $this->registerAttendee($event)->user;
        $other = $this->registerAttendee($event)->user;

        $this->actingAs($attendee)->post(route('partners.connect', [$event, $partner]));
        $lead = PartnerConnection::sole();

        $this->actingAs($attendee)->get(route('attendee.preferences.edit'))
            ->assertInertia(fn (Assert $page) => $page->has('partnerConnections', 1)->where('partnerConnections.0.partner', 'DataWorks'));

        // Someone else's lead is simply not found.
        $this->actingAs($other)->delete(route('attendee.partner-connections.destroy', $lead))->assertNotFound();

        $this->actingAs($attendee)->delete(route('attendee.partner-connections.destroy', $lead))->assertSessionHas('success');

        $this->assertFalse($lead->fresh()->isActive());
        $this->assertSame('withdrawn', ConsentRecord::latest('id')->first()->action);
        $this->actingAs($member)->get(route('partner.events.leads.index', [$partner, $event]))
            ->assertInertia(fn (Assert $page) => $page->has('leads', 0));
    }

    public function test_a_stand_scan_shows_only_first_name_and_organisation_until_the_attendee_agrees(): void
    {
        $event = $this->makeEvent();
        $partner = $this->partnerOn($event);
        $rep = $this->partnerMember($partner);
        ['registration' => $registration, 'ticket' => $ticket] = $this->registerPaidInPersonAttendee($event);
        $attendee = $registration->user;
        $attendee->update(['first_name' => 'Thabo', 'name' => 'Thabo Mokoena', 'organisation' => 'City of Joburg']);
        $code = $ticket->code;

        $lookup = $this->actingAs($rep)->postJson(route('partner.events.scan.lookup', [$partner, $event]), ['code' => $code])->assertOk();

        $this->assertSame('Thabo', $lookup->json('first_name'));
        $this->assertSame('City of Joburg', $lookup->json('organisation'));
        $this->assertStringContainsString('DataWorks', $lookup->json('consent_text'));
        $body = $lookup->getContent();
        $this->assertStringNotContainsString($attendee->email, $body);
        $this->assertStringNotContainsString('Mokoena', $body);
        $this->assertStringNotContainsString($code, $body);
        $this->assertSame(0, ConsentRecord::count());

        // Another rep can't answer for this scan.
        $otherRep = $this->partnerMember($partner);
        $this->actingAs($otherRep)->postJson(route('partner.events.scan.confirm', [$partner, $event, $lookup->json('scan_id')]))->assertNotFound();

        $this->actingAs($rep)->postJson(route('partner.events.scan.confirm', [$partner, $event, $lookup->json('scan_id')]))->assertOk();

        $lead = PartnerConnection::sole();
        $this->assertSame('badge_scan', $lead->source);
        $this->assertSame($rep->id, $lead->captured_by_user_id);
        $this->assertTrue($lead->isActive());

        // Single use, and a second scan of the same ticket says so.
        $this->actingAs($rep)->postJson(route('partner.events.scan.confirm', [$partner, $event, $lookup->json('scan_id')]))->assertNotFound();
        $this->actingAs($rep)->postJson(route('partner.events.scan.lookup', [$partner, $event]), ['code' => $code])->assertStatus(409);
    }

    public function test_declining_a_scan_stores_nothing_and_other_events_tickets_are_refused(): void
    {
        $event = $this->makeEvent();
        $elsewhere = $this->makeEvent();
        $partner = $this->partnerOn($event);
        $rep = $this->partnerMember($partner);
        ['ticket' => $ticket] = $this->registerPaidInPersonAttendee($event);
        ['ticket' => $otherTicket] = $this->registerPaidInPersonAttendee($elsewhere);

        $lookup = $this->actingAs($rep)->postJson(route('partner.events.scan.lookup', [$partner, $event]), ['code' => $ticket->code])->assertOk();
        $this->actingAs($rep)->deleteJson(route('partner.events.scan.decline', [$partner, $event, $lookup->json('scan_id')]))->assertOk();
        $this->actingAs($rep)->postJson(route('partner.events.scan.confirm', [$partner, $event, $lookup->json('scan_id')]))->assertNotFound();

        $this->actingAs($rep)->postJson(route('partner.events.scan.lookup', [$partner, $event]), ['code' => $otherTicket->code])->assertStatus(422);
        $this->actingAs($rep)->postJson(route('partner.events.scan.lookup', [$partner, $event]), ['code' => 'not-a-code'])->assertStatus(422);

        $this->assertSame(0, ConsentRecord::count());
        $this->assertSame(0, PartnerConnection::count());
    }

    public function test_the_csv_export_is_audited_and_holds_only_consented_leads(): void
    {
        $event = $this->makeEvent();
        $partner = $this->partnerOn($event);
        $member = $this->partnerMember($partner);
        $connected = $this->registerAttendee($event, 'online', ['name' => 'Thabo Mokoena'])->user;
        $this->registerAttendee($event, 'online', ['name' => 'Not Connected']);

        $this->actingAs($connected)->post(route('partners.connect', [$event, $partner]));

        $csv = $this->actingAs($member)->get(route('partner.events.leads.export', [$partner, $event]))->assertOk()->streamedContent();

        $this->assertStringContainsString('Thabo Mokoena', $csv);
        $this->assertStringNotContainsString('Not Connected', $csv);
        $this->assertDatabaseHas('audit_logs', ['action' => 'partner.leads_downloaded', 'subject_id' => $partner->id]);
    }

    public function test_only_the_partners_team_and_super_admins_open_its_portal(): void
    {
        $event = $this->makeEvent();
        $partner = $this->partnerOn($event);
        $otherPartner = $this->partnerOn($event, attributes: ['name' => 'Rival Ltd']);
        $rivalMember = $this->partnerMember($otherPartner);

        $this->actingAs($rivalMember)->get(route('partner.dashboard', $partner))->assertForbidden();
        $this->actingAs($rivalMember)->get(route('partner.events.leads.index', [$partner, $event]))->assertForbidden();
        $this->actingAs($this->registerAttendee($event)->user)->get(route('partner.dashboard', $partner))->assertForbidden();
        $this->actingAs($this->makeManagerFor($event))->get(route('partner.dashboard', $partner))->assertForbidden();

        $this->actingAs($this->makeSuperAdmin())->get(route('partner.dashboard', $partner))->assertOk();
        $this->actingAs($this->partnerMember($partner))->get(route('partner.dashboard', $partner))
            ->assertInertia(fn (Assert $page) => $page->component('Partner/Dashboard')->has('events', 1));
    }

    public function test_deleting_an_account_takes_it_off_every_partners_leads(): void
    {
        $event = $this->makeEvent();
        $partner = $this->partnerOn($event);
        $member = $this->partnerMember($partner);
        $attendee = $this->registerAttendee($event)->user;

        $this->actingAs($attendee)->post(route('partners.connect', [$event, $partner]), ['note' => 'Call me']);
        $lead = PartnerConnection::sole();
        PartnerLeadNote::create(['partner_connection_id' => $lead->id, 'user_id' => $member->id, 'body' => 'Met at stand']);

        app(AccountAnonymiser::class)->anonymise($attendee);

        $lead->refresh();
        $this->assertFalse($lead->isActive());
        $this->assertNull($lead->note);
        $this->assertSame(0, PartnerLeadNote::count());
        $this->assertFalse(app(ConsentService::class)->isGranted($attendee->fresh(), ConsentType::PartnerContact, $event, partner: $partner));
    }

    public function test_team_notes_are_added_only_to_the_partners_own_active_leads(): void
    {
        $event = $this->makeEvent();
        $partner = $this->partnerOn($event);
        $member = $this->partnerMember($partner);
        $rival = $this->partnerOn($event, attributes: ['name' => 'Rival Ltd']);
        $attendee = $this->registerAttendee($event)->user;

        $this->actingAs($attendee)->post(route('partners.connect', [$event, $rival]));
        $rivalLead = PartnerConnection::sole();

        $this->actingAs($member)
            ->post(route('partner.events.leads.notes.store', [$partner, $event, $rivalLead]), ['body' => 'snooping'])
            ->assertNotFound();
        $this->assertSame(0, PartnerLeadNote::count());
    }
}
