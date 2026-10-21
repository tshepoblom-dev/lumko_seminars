<?php

namespace Tests\Feature\Public;

use App\Enums\ConsentType;
use App\Enums\PaymentStatus;
use App\Enums\TicketStatus;
use App\Models\ConsentRecord;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Payment;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Voucher;
use App\Notifications\RegistrationAddedToAccount;
use App\Services\ConsentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesEventWorld;
use Tests\TestCase;

/**
 * A guest registers an email that already has a confirmed account (owner
 * decision 2026-10-13): the registration is saved, held at arm's length
 * until the owner signs in, and the owner can cancel it from their email.
 */
class ExistingAccountRegistrationTest extends TestCase
{
    use CreatesEventWorld, RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Someone',
            'last_name' => 'Else',
            'email' => 'owner@example.com',
            'mobile' => '0821234567',
            'organisation' => 'Typed Organisation',
            'job_title' => 'Typed Title',
            'industry' => config('seminar.industries')[0],
            'attendance_mode' => 'online',
            'networking_consent' => true,
            'lumko_marketing_consent' => true,
            'accept_legal' => true,
        ], $overrides);
    }

    private function owner(array $attributes = []): User
    {
        return User::factory()->create([
            'email' => 'owner@example.com', 'email_verified_at' => now(),
            'first_name' => 'Olive', 'last_name' => 'Owner', 'name' => 'Olive Owner',
            'mobile' => null, 'organisation' => null, 'job_title' => null,
            ...$attributes,
        ]);
    }

    /** Registers as a guest and returns the registration and the cancel link from the owner's email. */
    private function registerAsGuest(Event $event, User $owner, array $overrides = []): array
    {
        Notification::fake();

        $this->post(route('event.register.store'), $this->payload($overrides))
            ->assertRedirect(route('event.register.confirmation'));

        $registration = EventRegistration::where('event_id', $event->id)->where('user_id', $owner->id)->sole();

        $url = null;
        Notification::assertSentTo($owner, RegistrationAddedToAccount::class, function (RegistrationAddedToAccount $n) use (&$url) {
            $url = $n->cancelUrl();

            return true;
        });

        return [$registration, $url];
    }

    public function test_the_registration_is_saved_and_only_blank_profile_fields_are_filled(): void
    {
        $event = $this->makeEvent();
        $blank = $this->owner();

        [$registration] = $this->registerAsGuest($event, $blank, ['attendance_mode' => 'in_person']);

        $this->assertGuest();
        $this->assertSame('in_person', $registration->attendance_mode->value);
        $this->assertSame(config('seminar.industries')[0], $registration->industry);
        $this->assertSame(PaymentStatus::Pending, $registration->payment->status);
        $this->assertSame(TicketStatus::Pending, $registration->ticket->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'registration.created_for_existing_account']);

        // A sign-up-only account had nothing on file, so the form fills it in…
        $blank->refresh();
        $this->assertSame('Typed Organisation', $blank->organisation);
        $this->assertSame('Typed Title', $blank->job_title);
        $this->assertSame('0821234567', $blank->mobile);
        // …but never the name, and nothing is recorded as accepted for them.
        $this->assertSame('Olive Owner', $blank->name);
        $this->assertDatabaseMissing('legal_acceptances', ['user_id' => $blank->id, 'source' => 'registration']);
    }

    public function test_details_already_on_the_account_are_never_overwritten(): void
    {
        $event = $this->makeEvent();
        $owner = $this->owner(['mobile' => '0110000000', 'organisation' => 'Real Organisation', 'job_title' => 'Real Title']);

        $this->registerAsGuest($event, $owner);

        $owner->refresh();
        $this->assertSame('Real Organisation', $owner->organisation);
        $this->assertSame('Real Title', $owner->job_title);
        $this->assertSame('0110000000', $owner->mobile);
    }

    public function test_consents_wait_until_the_owner_signs_in(): void
    {
        $event = $this->makeEvent();
        $owner = $this->owner();
        $consents = app(ConsentService::class);

        $this->registerAsGuest($event, $owner);

        $this->assertFalse($consents->isGranted($owner, ConsentType::Networking, $event));
        $this->assertFalse($consents->isGranted($owner, ConsentType::LumkoMarketing));
        $this->assertSame(2, ConsentRecord::where('user_id', $owner->id)->whereNull('effective_at')->count());

        $this->post(route('login'), ['email' => 'owner@example.com', 'password' => 'password']);
        $this->assertAuthenticatedAs($owner);

        $this->assertTrue($consents->isGranted($owner, ConsentType::Networking, $event));
        $this->assertTrue($consents->isGranted($owner, ConsentType::LumkoMarketing));
    }

    public function test_the_owner_can_cancel_it_from_the_email_and_nothing_is_left_behind(): void
    {
        $event = $this->makeEvent();
        $owner = $this->owner();
        $voucher = Voucher::factory()->create(['event_id' => $event->id]);
        $consents = app(ConsentService::class);

        [$registration, $url] = $this->registerAsGuest($event, $owner, ['attendance_mode' => 'in_person', 'voucher_code' => $voucher->code]);
        $this->assertNotNull($voucher->fresh()->redeemed_at);

        $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Public/RegistrationNotMe')
            ->where('state', 'confirm')
            ->where('registration.event', $event->name));

        $this->post($url)->assertRedirect(route('registration.not-me.done'));

        $this->assertModelMissing($registration);
        $this->assertSame(0, Payment::where('event_registration_id', $registration->id)->count());
        $this->assertSame(0, Ticket::where('event_registration_id', $registration->id)->count());
        $this->assertNull($voucher->fresh()->redeemed_at); // free to use again
        $this->assertModelExists($owner);
        $this->assertDatabaseHas('audit_logs', ['action' => 'registration.cancelled_not_me']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'voucher.released']);

        // The consent ticked on that form can never take effect now.
        $this->post(route('login'), ['email' => 'owner@example.com', 'password' => 'password']);
        $this->assertAuthenticatedAs($owner);
        $this->assertFalse($consents->isGranted($owner, ConsentType::Networking, $event));
        $this->assertFalse($consents->isGranted($owner, ConsentType::LumkoMarketing));
        $this->assertSame(2, ConsentRecord::where('user_id', $owner->id)->where('action', 'voided')->count());

        // The link now has nothing to cancel.
        $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->where('state', 'gone'));
        $this->get(route('registration.not-me.done'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('state', 'cancelled'));
    }

    public function test_cancelling_never_withdraws_a_consent_that_already_stood(): void
    {
        $event = $this->makeEvent();
        $owner = $this->owner();
        $consents = app(ConsentService::class);
        $consents->grant($owner, ConsentType::LumkoMarketing, null, source: 'account', effectiveImmediately: true);

        [, $url] = $this->registerAsGuest($event, $owner);
        $this->post($url)->assertRedirect(route('registration.not-me.done'));

        $this->assertTrue($consents->isGranted($owner, ConsentType::LumkoMarketing));
    }

    public function test_a_registration_with_a_proof_of_payment_cannot_be_cancelled_from_the_link(): void
    {
        $event = $this->makeEvent();
        $owner = $this->owner();

        [$registration, $url] = $this->registerAsGuest($event, $owner, ['attendance_mode' => 'in_person']);
        $registration->payment->update(['status' => PaymentStatus::UnderReview, 'proof_path' => 'proofs/paid.pdf']);

        $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->where('state', 'blocked'));
        $this->from($url)->post($url)->assertRedirect($url);

        $this->assertModelExists($registration);
    }

    public function test_the_cancel_link_must_be_signed_and_expires(): void
    {
        $event = $this->makeEvent();
        $owner = $this->owner();

        [$registration, $url] = $this->registerAsGuest($event, $owner);

        $this->get(route('registration.not-me', $registration->public_id))->assertForbidden();
        $this->post(route('registration.not-me.cancel', $registration->public_id))->assertForbidden();

        $this->travel(RegistrationAddedToAccount::CANCEL_LINK_DAYS + 1)->days();
        $this->get($url)->assertForbidden();
        $this->post($url)->assertForbidden();

        $this->assertModelExists($registration);
    }

    public function test_the_email_says_what_happened_and_how_to_undo_it(): void
    {
        $event = $this->makeEvent(['name' => 'The Power of Data']);
        $owner = $this->owner();

        [$registration, $url] = $this->registerAsGuest($event, $owner);

        $mail = (new RegistrationAddedToAccount($event, $registration))->toMail($owner);
        $text = implode("\n", [...$mail->introLines, ...$mail->outroLines]);

        $this->assertSame("You're registered: The Power of Data", $mail->subject);
        $this->assertStringContainsString('owner@example.com was registered for "The Power of Data"', $text);
        $this->assertStringContainsString('without signing in', $text);
        $this->assertStringContainsString('/registration-not-me/'.$registration->public_id, $text);
        $this->assertSame(route('login'), $mail->actionUrl);
    }
}
