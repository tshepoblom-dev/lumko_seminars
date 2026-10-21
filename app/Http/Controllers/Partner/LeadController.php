<?php

namespace App\Http\Controllers\Partner;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Partner\Concerns\ResolvesPartnerEvent;
use App\Models\Event;
use App\Models\Partner;
use App\Models\PartnerConnection;
use App\Models\PartnerLeadNote;
use App\Support\Audit;
use App\Support\Csv;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A partner's leads for one event: only attendees who chose to share their
 * details with this partner (partner_contact consent), only while that
 * choice stands — a withdrawal removes the lead from here at once. What's
 * shown is exactly what the consent sentence names: name, email,
 * organisation and job title, plus the attendee's own note.
 *
 * Every view and download is audited, as for a speaker's report.
 */
class LeadController extends Controller
{
    use ResolvesPartnerEvent;

    public function index(Request $request, Partner $partner, Event $event): Response
    {
        $eventPartner = $this->eventPartner($partner, $event);

        $leads = $this->leads($partner, $event);

        Audit::record('partner.leads_viewed', subject: $partner, eventId: $event->id, after: ['leads_shown' => $leads->count()]);

        $response = Inertia::render('Partner/Leads', [
            ...$this->portalProps($partner, $event, $eventPartner),
            'sources' => collect(PartnerConnection::SOURCES)->map(fn ($s) => ['value' => $s, 'label' => PartnerConnection::sourceLabel($s)])->values(),
            'leads' => $leads->map(fn (PartnerConnection $c) => [
                'id' => $c->id,
                'name' => $c->user?->name,
                'email' => $c->user?->email,
                'organisation' => $c->user?->organisation,
                'job_title' => $c->user?->job_title,
                'source' => $c->source,
                'source_label' => PartnerConnection::sourceLabel($c->source),
                'note' => $c->note,
                'captured_by' => $c->capturedBy?->name,
                'since' => $c->granted_at?->toIso8601String(),
                'team_notes' => $c->notes->map(fn (PartnerLeadNote $n) => [
                    'id' => $n->id,
                    'body' => $n->body,
                    'author' => $n->author?->name,
                    'at' => $n->created_at?->toIso8601String(),
                ]),
            ])->values(),
        ])->toResponse($request);

        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    public function export(Partner $partner, Event $event): StreamedResponse
    {
        $this->eventPartner($partner, $event);

        $leads = $this->leads($partner, $event);

        Audit::record('partner.leads_downloaded', subject: $partner, eventId: $event->id, after: ['rows' => $leads->count()]);

        $filename = 'leads-'.Str::slug($partner->name).'-'.($event->slug ?: $event->id).'.csv';

        return response()->streamDownload(function () use ($leads) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel reads accents correctly

            fputcsv($out, ['Name', 'Email', 'Organisation', 'Job title', 'How they connected', 'Their note', 'Connected on']);

            foreach ($leads as $c) {
                fputcsv($out, array_map([Csv::class, 'safe'], [
                    $c->user?->name,
                    $c->user?->email,
                    $c->user?->organisation,
                    $c->user?->job_title,
                    PartnerConnection::sourceLabel($c->source),
                    $c->note,
                    $c->granted_at?->format('Y-m-d'),
                ]));
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    public function storeNote(Request $request, Partner $partner, Event $event, PartnerConnection $partnerConnection): RedirectResponse
    {
        $this->eventPartner($partner, $event);
        abort_unless(
            $partnerConnection->partner_id === $partner->id
                && $partnerConnection->event_id === $event->id
                && $partnerConnection->isActive(),
            404,
        );

        $data = $request->validate(['body' => ['required', 'string', 'max:2000']]);

        $partnerConnection->notes()->create(['user_id' => $request->user()->id, 'body' => $data['body']]);

        return back()->with('success', 'Note added.');
    }

    private function leads(Partner $partner, Event $event)
    {
        return PartnerConnection::where('partner_id', $partner->id)
            ->where('event_id', $event->id)
            ->active()
            ->with(['user:id,name,email,organisation,job_title', 'capturedBy:id,name', 'notes.author:id,name'])
            ->orderByDesc('granted_at')
            ->get();
    }
}
