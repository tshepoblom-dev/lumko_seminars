<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\EventRegistration;
use App\Services\TicketPdf;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use SimpleSoftwareIO\QrCode\Facades\QrCode;
use Symfony\Component\HttpFoundation\HeaderUtils;

class TicketController extends Controller
{
    public function show(): Response
    {
        $registration = $this->inPersonRegistration(['ticket', 'event.programmeSessions']);

        $ticket = $registration->ticket;
        $confirmed = $ticket && $ticket->status->value === 'confirmed';

        return Inertia::render('Public/Ticket', [
            'event' => [
                'name' => $registration->event->name,
                // Display only: the ticket is set as a credential with when and where.
                'starts_at' => $registration->event->starts_at?->toIso8601String(),
                'ends_at' => $registration->event->ends_at?->toIso8601String(),
                'venue_name' => $registration->event->venue_name,
                'venue_address' => $registration->event->venue_address,
                'programme' => $registration->event->programmeSessions->map(fn ($session) => [
                    'title' => $session->title,
                    'start_time' => $session->start_time?->format('H:i'),
                    'end_time' => $session->end_time?->format('H:i'),
                ]),
            ],
            'attendee' => [
                'name' => trim(Auth::user()->first_name.' '.Auth::user()->last_name) ?: Auth::user()->name,
                'organisation' => Auth::user()->organisation,
            ],
            'admission' => $registration->isComplimentary() ? 'Complimentary guest' : 'In person',
            'confirmed' => $confirmed,
            // generate() returns an HtmlString; cast it, or Inertia serialises
            // the object as `{}` and the page shows "[object Object]".
            'qrSvg' => $confirmed ? (string) QrCode::format('svg')->size(220)->generate($ticket->code) : null,
            // Text alternative to the QR image, and the manual-entry fallback
            // for door staff when a screen won't scan.
            'code' => $confirmed ? $ticket->code : null,
        ]);
    }

    /**
     * The ticket as a PDF, generated on demand and never stored. Same
     * registration as the page; nothing to download until it's confirmed.
     */
    public function pdf(TicketPdf $pdf): HttpResponse
    {
        $ticket = $this->inPersonRegistration(['ticket'])->ticket;

        abort_unless($ticket && $pdf->canRender($ticket), 404);

        return response($pdf->render($ticket), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $pdf->filename($ticket)),
            // It carries the check-in code: never cached, never sniffed (as SafeFileResponse).
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** The signed-in attendee's latest in-person registration (404 without one). */
    private function inPersonRegistration(array $with): EventRegistration
    {
        return Auth::user()->registrations()
            ->with($with)
            ->where('attendance_mode', 'in_person')
            ->latest()
            ->firstOrFail();
    }
}
