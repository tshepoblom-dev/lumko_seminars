<?php

namespace Tests\Feature;

use App\Models\Voucher;
use App\Notifications\PaymentApproved;
use App\Notifications\RegistrationConfirmed;
use App\Notifications\TicketConfirmed;
use App\Services\PaymentService;
use App\Services\TicketPdf;
use App\Services\VoucherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\Concerns\CreatesEventWorld;
use Tests\TestCase;

/** The PDF ticket goes with the email that confirms the ticket. */
class TicketPdfEmailTest extends TestCase
{
    use CreatesEventWorld, RefreshDatabase;

    private function assertPdfAttached(MailMessage $mail): void
    {
        $this->assertCount(1, $mail->rawAttachments);
        $attachment = $mail->rawAttachments[0];
        $this->assertStringStartsWith('%PDF-', $attachment['data']);
        $this->assertStringEndsWith('-ticket.pdf', $attachment['name']);
        $this->assertSame('application/pdf', $attachment['options']['mime']);
        $this->assertStringContainsString('attached as a PDF', implode(' ', $mail->outroLines));
    }

    public function test_the_payment_approved_email_carries_the_pdf_and_is_sent_after_commit(): void
    {
        Notification::fake();
        $event = $this->makeEvent();
        ['registration' => $registration, 'payment' => $payment] = $this->registerPaidInPersonAttendee($event, approved: false);

        app(PaymentService::class)->approve($payment, 'Confirmed on bank statement.', manualOverride: true, actor: $this->makeSuperAdmin());

        Notification::assertSentTo($registration->user, PaymentApproved::class, function (PaymentApproved $n) use ($registration) {
            $this->assertPdfAttached($n->toMail($registration->user));

            return $n->afterCommit === true;
        });
    }

    public function test_a_redeemed_invitation_code_emails_the_pdf(): void
    {
        $event = $this->makeEvent();
        $registration = $this->registerAttendee($event);
        $ticket = app(VoucherService::class)->redeem(Voucher::factory()->create(['event_id' => $event->id]), $registration, $registration->user);

        $this->assertPdfAttached((new TicketConfirmed($ticket))->toMail($registration->user));
        $this->assertPdfAttached((new RegistrationConfirmed($event, $registration->fresh()))->toMail($registration->user));
    }

    public function test_no_pdf_goes_out_before_the_ticket_is_valid(): void
    {
        $event = $this->makeEvent();
        ['registration' => $registration, 'ticket' => $ticket] = $this->registerPaidInPersonAttendee($event, approved: false);

        // A paid registration's confirmation email comes before payment.
        $this->assertSame([], (new RegistrationConfirmed($event, $registration))->toMail($registration->user)->rawAttachments);
        $this->assertSame([], (new TicketConfirmed($ticket))->toMail($registration->user)->rawAttachments);
    }

    public function test_a_rendering_failure_still_sends_the_email_without_the_attachment(): void
    {
        $event = $this->makeEvent();
        ['registration' => $registration, 'ticket' => $ticket] = $this->registerPaidInPersonAttendee($event);
        $this->partialMock(TicketPdf::class, fn ($mock) => $mock->shouldReceive('render')->andThrow(new RuntimeException('font cache not writable')));

        $mail = (new TicketConfirmed($ticket))->toMail($registration->user);

        $this->assertSame([], $mail->rawAttachments);
        $this->assertStringContainsString('download your ticket as a PDF from your dashboard', implode(' ', $mail->outroLines));
    }
}
