<?php

namespace App\Services;

use App\Enums\AttendanceMode;
use App\Enums\TicketStatus;
use App\Models\ProgrammeSession;
use App\Models\Ticket;
use App\Support\TicketQr;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use LogicException;

/**
 * The digital ticket as an A4 PDF (resources/views/pdf/ticket.blade.php):
 * emailed when a ticket is confirmed (AttachesTicketPdf) and downloadable
 * from the ticket page and dashboard (TicketController::pdf). Rendered in
 * memory on demand, never written to disk: it carries the raw check-in
 * code. Only a ticket that would pass check-in can be rendered.
 */
class TicketPdf
{
    /** Same rules CheckInService applies at the door. */
    public function canRender(Ticket $ticket): bool
    {
        $registration = $ticket->registration;

        return $ticket->status === TicketStatus::Confirmed
            && $registration !== null
            && $registration->status === 'registered'
            && $registration->attendance_mode === AttendanceMode::InPerson;
    }

    /** @return string the PDF bytes */
    public function render(Ticket $ticket): string
    {
        $ticket->loadMissing(['registration.user', 'registration.event.programmeSessions.speakers']);

        if (! $this->canRender($ticket)) {
            throw new LogicException('Only a confirmed in-person ticket can be rendered.');
        }

        // dompdf caches font metrics here and fails if the folder is missing.
        File::ensureDirectoryExists(storage_path('fonts'));

        return Pdf::loadView('pdf.ticket', $this->viewData($ticket))
            ->setPaper('a4', 'portrait')
            ->output();
    }

    /** No code or name in it: just the event. */
    public function filename(Ticket $ticket): string
    {
        return Str::slug($ticket->registration->event->name ?: 'event').'-ticket.pdf';
    }

    protected function viewData(Ticket $ticket): array
    {
        $registration = $ticket->registration;
        $event = $registration->event;
        $user = $registration->user;
        $timezone = config('app.timezone');
        $starts = $event->starts_at?->copy()->timezone($timezone);
        $ends = $event->ends_at?->copy()->timezone($timezone);

        $sessions = $event->programmeSessions->values();

        return [
            'event' => [
                'name' => $event->name,
                'day' => $starts?->format('d'),
                'month_year' => $starts ? strtoupper($starts->format('M Y')) : null,
                'long_date' => $starts?->format('l j F Y'),
                'time' => $starts ? $starts->format('H:i').($ends ? '–'.$ends->format('H:i') : '') : null,
                'start_clock' => $starts?->format('H:i'),
                'venue_name' => $event->venue_name,
                'venue_address' => $event->venue_address,
            ],
            'attendee' => [
                'name' => trim($user->first_name.' '.$user->last_name) ?: $user->name,
                // One line on the credential; the full name is on their account.
                'organisation' => $user->organisation ? Str::limit($user->organisation, 64) : null,
            ],
            'admission' => $registration->isComplimentary() ? 'Complimentary guest' : 'In person',
            'programme' => $sessions->map(fn (ProgrammeSession $session, int $i) => [
                'index' => str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT),
                'time' => $session->start_time
                    ? $session->start_time->format('H:i').($session->end_time ? '–'.$session->end_time->format('H:i') : '')
                    : '',
                'title' => $session->title,
                'speakers' => ProgrammeSession::speakerLine($session),
            ])->all(),
            'ruler' => $this->ruler($sessions),
            'qr' => TicketQr::dataUri($ticket->code),
            // Grouped for reading aloud and typing at the door; CheckInService
            // ignores the spaces.
            'code_lines' => array_map(
                fn (array $groups) => implode(' ', $groups),
                array_chunk(str_split($ticket->code, 5), 3),
            ),
            'logo' => 'data:image/png;base64,'.base64_encode((string) file_get_contents(resource_path('pdf/logo-light-on-ink.png'))),
            'fonts' => $this->fonts(),
            'organiser' => $this->organiser(),
            'host' => parse_url((string) config('app.url'), PHP_URL_HOST) ?: config('app.url'),
            'issued' => now()->timezone($timezone)->format('j F Y'),
        ];
    }

    /**
     * The programme to scale, as on the web ticket's TimeRuler: each
     * session's left offset and width as a percentage of the morning.
     *
     * @return array<int, array{index: string, left: float, width: float}>
     */
    private function ruler($sessions): array
    {
        $spans = $sessions->values()->map(function (ProgrammeSession $session, int $i) {
            if (! $session->start_time || ! $session->end_time) {
                return null;
            }

            $a = $session->start_time->hour * 60 + $session->start_time->minute;
            $b = $session->end_time->hour * 60 + $session->end_time->minute;

            return $b > $a ? ['index' => str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT), 'a' => $a, 'b' => $b] : null;
        })->filter()->values();

        if ($spans->isEmpty()) {
            return [];
        }

        $start = $spans->min('a');
        $total = max(1, $spans->max('b') - $start);

        return $spans->map(fn (array $s) => [
            'index' => $s['index'],
            'left' => round(($s['a'] - $start) / $total * 100, 3),
            'width' => round(($s['b'] - $s['a']) / $total * 100, 3),
        ])->all();
    }

    /**
     * Plain absolute paths with forward slashes: dompdf loads these on both
     * Windows (dev) and Linux, whereas a file:///C:/ URL silently falls
     * back to Helvetica on Windows.
     */
    private function fonts(): array
    {
        $url = fn (string $file): string => str_replace('\\', '/', resource_path('fonts/pdf/'.$file));

        return [
            'display' => $url('Archivo-Condensed-ExtraBold.ttf'),
            'name' => $url('Archivo-SemiCondensed-Bold.ttf'),
            'body' => $url('Poppins-Regular.ttf'),
            'body_medium' => $url('Poppins-Medium.ttf'),
            'body_semibold' => $url('Poppins-SemiBold.ttf'),
        ];
    }

    /** The organiser's details, leaving out anything still "[TO COMPLETE]". */
    private function organiser(): array
    {
        $placeholder = config('legal.placeholder');

        return collect([
            'legal_name' => config('legal.organiser.legal_name'),
            'registration_number' => config('legal.organiser.registration_number'),
            'physical_address' => config('legal.organiser.physical_address'),
            'contact_email' => config('legal.organiser.contact_email'),
        ])->map(fn ($value) => filled($value) && $value !== $placeholder ? $value : null)->all();
    }
}
