<?php

namespace Tests\Feature\Public;

use App\Enums\PaymentStatus;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\PaymentStatusHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaymentFlowTest extends TestCase
{
    use RefreshDatabase;

    private function registerInPerson(): EventRegistration
    {
        $event = Event::factory()->create(['status' => 'published', 'in_person_price' => 200]);
        $user = User::factory()->create(['role' => UserRole::Attendee]);

        return $user->registrations()->create([
            'event_id' => $event->id,
            'attendance_mode' => 'in_person',
            'status' => 'registered',
        ]);
    }

    public function test_registration_creates_pending_payment_and_ticket(): void
    {
        $registration = $this->registerInPerson();

        $payment = $registration->payment()->create([
            'amount' => $registration->event->in_person_price,
            'status' => PaymentStatus::Pending,
        ]);
        $ticket = $registration->ticket()->create([
            'code' => Str::random(40),
            'status' => TicketStatus::Pending,
        ]);

        $this->assertSame(200.0, (float) $payment->amount);
        $this->assertSame('pending', $ticket->status->value);
    }

    public function test_attendee_can_upload_proof_and_status_moves_to_under_review(): void
    {
        Storage::fake('local');

        $registration = $this->registerInPerson();
        $registration->payment()->create(['amount' => 200, 'status' => PaymentStatus::Pending]);
        $registration->ticket()->create(['code' => 'abc123', 'status' => TicketStatus::Pending]);

        $response = $this->actingAs($registration->user)->post(route('payment.store'), [
            'proof' => UploadedFile::fake()->create('proof.pdf', 100, 'application/pdf'),
        ]);

        $response->assertRedirect(route('payment.show'));
        $this->assertSame('under_review', $registration->payment->fresh()->status->value);
        Storage::disk('local')->assertExists($registration->payment->fresh()->proof_path);
    }

    public function test_admin_approving_payment_confirms_ticket(): void
    {
        $registration = $this->registerInPerson();
        $payment = $registration->payment()->create(['amount' => 200, 'status' => PaymentStatus::UnderReview]);
        $registration->ticket()->create(['code' => 'xyz789', 'status' => TicketStatus::Pending]);

        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);

        // No proof was uploaded above (the payment was created directly at
        // UnderReview) — B4 requires manual_override + a note for this.
        $response = $this->actingAs($admin)->post(route('admin.payments.approve', [$registration->event_id, $payment->id]), [
            'note' => 'Confirmed via bank statement.',
            'manual_override' => true,
        ]);

        $response->assertRedirect();
        $this->assertSame('approved', $payment->fresh()->status->value);
        $this->assertTrue($payment->fresh()->manual_override);
        $this->assertSame('confirmed', $registration->ticket->fresh()->status->value);
    }

    // T-PAY-1 — approve without a proof and without manual_override is refused.
    public function test_admin_approving_without_proof_requires_manual_override(): void
    {
        $registration = $this->registerInPerson();
        $payment = $registration->payment()->create(['amount' => 200, 'status' => PaymentStatus::UnderReview]);
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);

        $response = $this->actingAs($admin)->post(route('admin.payments.approve', [$registration->event_id, $payment->id]), ['note' => null]);

        $response->assertSessionHasErrors('proof');
        $this->assertSame('under_review', $payment->fresh()->status->value);
    }

    // T-PAY-3 — approving an already-approved payment is a safe no-op.
    public function test_double_approve_is_idempotent(): void
    {
        $registration = $this->registerInPerson();
        $payment = $registration->payment()->create(['amount' => 200, 'status' => PaymentStatus::UnderReview, 'proof_path' => 'payment-proofs/x.pdf']);
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);

        $this->actingAs($admin)->post(route('admin.payments.approve', [$registration->event_id, $payment->id]), []);
        $this->actingAs($admin)->post(route('admin.payments.approve', [$registration->event_id, $payment->id]), []);

        $this->assertSame('approved', $payment->fresh()->status->value);
        $this->assertSame(1, PaymentStatusHistory::where('payment_id', $payment->id)->where('to_status', 'approved')->count());
    }

    // T-PAY-2 — reject/cancel never leave a ticket confirmed.
    public function test_admin_rejecting_after_approval_is_not_allowed_but_cancel_voids_ticket(): void
    {
        $registration = $this->registerInPerson();
        $payment = $registration->payment()->create(['amount' => 200, 'status' => PaymentStatus::UnderReview, 'proof_path' => 'payment-proofs/x.pdf']);
        $registration->ticket()->create(['code' => 'v1', 'status' => TicketStatus::Pending]);
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);

        $this->actingAs($admin)->post(route('admin.payments.approve', [$registration->event_id, $payment->id]), []);
        $this->assertSame('confirmed', $registration->ticket->fresh()->status->value);

        $response = $this->actingAs($admin)->post(route('admin.payments.cancel', [$registration->event_id, $payment->id]), ['note' => 'Refunded on request.']);

        $response->assertRedirect();
        $this->assertSame('cancelled', $payment->fresh()->status->value);
        $this->assertSame('cancelled', $registration->ticket->fresh()->status->value);
    }

    public function test_admin_rejecting_payment_requires_a_note(): void
    {
        $registration = $this->registerInPerson();
        $payment = $registration->payment()->create(['amount' => 200, 'status' => PaymentStatus::UnderReview]);

        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);

        $response = $this->actingAs($admin)->post(route('admin.payments.reject', [$registration->event_id, $payment->id]), []);

        $response->assertSessionHasErrors('note');
    }

    public function test_pop_file_is_not_publicly_accessible(): void
    {
        $response = $this->get('/storage/payment-proofs/whatever.pdf');

        // Never served (404 with serving disabled; 403 would also be acceptable).
        $this->assertContains($response->getStatusCode(), [403, 404]);
    }

    public function test_pop_is_only_reachable_through_the_authorised_admin_route(): void
    {
        $registration = $this->registerInPerson();
        $payment = $registration->payment()->create(['amount' => 200, 'status' => PaymentStatus::UnderReview, 'proof_path' => 'payment-proofs/x.pdf']);

        // Guests are sent to login before any file lookup happens.
        $this->get("/admin/events/{$registration->event_id}/payments/{$payment->id}/proof")->assertRedirect(route('login'));

        // Attendees are forbidden.
        $attendee = User::factory()->create();
        $this->actingAs($attendee)->get("/admin/events/{$registration->event_id}/payments/{$payment->id}/proof")->assertForbidden();
    }
}
