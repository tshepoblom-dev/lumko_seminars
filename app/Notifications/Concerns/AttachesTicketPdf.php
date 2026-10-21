<?php

namespace App\Notifications\Concerns;

use App\Models\Ticket;
use App\Services\TicketPdf;
use Illuminate\Notifications\Messages\MailMessage;
use Throwable;

/**
 * Attaches the PDF ticket (TicketPdf) to a "your ticket is confirmed"
 * email. Rendered here, inside toMail — i.e. in the queue worker at send
 * time, from a freshly loaded ticket — so the queued job only ever carries
 * model ids, never the PDF or the code. A rendering failure never costs the
 * attendee the email itself: it goes without the attachment and points to
 * the dashboard download instead.
 */
trait AttachesTicketPdf
{
    protected function attachTicketPdf(MailMessage $mail, ?Ticket $ticket): MailMessage
    {
        $ticket = $ticket ? Ticket::with(['registration.user', 'registration.event.programmeSessions.speakers'])->find($ticket->id) : null;
        $pdf = app(TicketPdf::class);

        if (! $ticket || ! $pdf->canRender($ticket)) {
            return $mail;
        }

        try {
            $mail->attachData($pdf->render($ticket), $pdf->filename($ticket), ['mime' => 'application/pdf']);
        } catch (Throwable $e) {
            report($e);

            return $mail->line('You can download your ticket as a PDF from your dashboard.');
        }

        return $mail->line('Your ticket is attached as a PDF: print it or keep it on your phone. You can download it again from your dashboard at any time.');
    }
}
