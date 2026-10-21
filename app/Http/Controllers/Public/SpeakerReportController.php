<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Services\SpeakerReport;
use App\Support\Audit;
use App\Support\SpeakerReportLink;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The speaker's report, reached by the expiring link emailed from
 * Event → Speakers. No login (speakers aren't user accounts): the link is
 * the credential. Every view and download is audited, because the page
 * shows attendees' contact details.
 */
class SpeakerReportController extends Controller
{
    public function show(string $token, SpeakerReport $report): Response
    {
        [$event, $speaker] = SpeakerReportLink::resolve($token);

        $data = $report->build($event, $speaker);

        Audit::record('speaker.report_viewed', subject: $speaker, actor: null, eventId: $event->id, after: [
            'contacts_shown' => count($data['contacts']),
        ]);

        $response = Inertia::render('Public/SpeakerReport', [
            ...$data,
            'csvUrl' => route('speaker-report.contacts', $token),
            'preview' => false,
        ])->toResponse(request());

        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }

    public function contacts(string $token, SpeakerReport $report): StreamedResponse
    {
        [$event, $speaker] = SpeakerReportLink::resolve($token);

        Audit::record('speaker.contacts_downloaded', subject: $speaker, actor: null, eventId: $event->id, after: [
            'rows' => $report->contacts($event, $speaker)->count(),
        ]);

        return $report->contactsCsv($event, $speaker);
    }
}
