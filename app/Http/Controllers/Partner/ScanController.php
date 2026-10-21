<?php

namespace App\Http\Controllers\Partner;

use App\Enums\ConsentType;
use App\Enums\PartnerFeature;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Partner\Concerns\ResolvesPartnerEvent;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Partner;
use App\Models\Ticket;
use App\Services\ConsentService;
use App\Support\Audit;
use App\Support\TicketCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

/**
 * Leads at the stand. A partner rep scans an attendee's ticket QR (or
 * types the code); the rep's screen then shows only the attendee's first
 * name and organisation, with the exact consent sentence, and the ATTENDEE
 * taps to agree on the rep's device. Scanning alone shares nothing: no
 * email, no ticket code back, and declining stores no personal data.
 *
 * Between the scan and the attendee's answer, the pending scan lives in
 * the cache for a few minutes under a random id, never in the browser.
 */
class ScanController extends Controller
{
    use ResolvesPartnerEvent;

    private const PENDING_MINUTES = 5;

    public function show(Partner $partner, Event $event): Response
    {
        $eventPartner = $this->eventPartner($partner, $event);
        $this->requireFeature($eventPartner, PartnerFeature::LeadsScan);

        // The stand QR: attendees scan it with their own phone and land on
        // the partner page with its Connect button.
        $standUrl = $eventPartner->hasFeature(PartnerFeature::ProfilePage) && $eventPartner->is_published
            ? route('partners.show', [$event, $partner])
            : null;

        return Inertia::render('Partner/Scan', [
            ...$this->portalProps($partner, $event, $eventPartner),
            'standUrl' => $standUrl,
            'standQrSvg' => $standUrl ? (string) QrCode::format('svg')->size(220)->margin(1)->generate($standUrl) : null,
        ]);
    }

    public function lookup(Request $request, Partner $partner, Event $event, ConsentService $consent): JsonResponse
    {
        $eventPartner = $this->eventPartner($partner, $event);
        $this->requireFeature($eventPartner, PartnerFeature::LeadsScan);

        $data = $request->validate(['code' => ['required', 'string', 'max:200']]);

        $ticket = Ticket::where('code_hash', TicketCode::hash((string) preg_replace('/\s+/', '', $data['code'])))
            ->with('registration.user')
            ->first();

        $registration = $ticket?->registration;

        if (! $registration || $registration->event_id !== $event->id || $registration->status !== 'registered' || ! $registration->user) {
            return response()->json(['message' => 'That ticket isn’t for this event. Ask the attendee to open their ticket again.'], 422);
        }

        $attendee = $registration->user;

        if ($attendee->isStaff() || $partner->hasMember($attendee)) {
            return response()->json(['message' => 'That ticket belongs to an organiser or someone on your own team.'], 422);
        }

        if ($consent->isGranted($attendee, ConsentType::PartnerContact, $event, partner: $partner)) {
            return response()->json(['message' => 'Already connected: this person is in your leads.', 'already' => true], 409);
        }

        $scanId = (string) Str::uuid();
        Cache::put($this->cacheKey($scanId), [
            'registration_id' => $registration->id,
            'partner_id' => $partner->id,
            'event_id' => $event->id,
            'rep_id' => $request->user()->id,
        ], now()->addMinutes(self::PENDING_MINUTES));

        [, $consentText] = $consent->wording(ConsentType::PartnerContact, partner: $partner);

        return response()->json([
            'scan_id' => $scanId,
            'first_name' => $attendee->first_name ?: Str::before((string) $attendee->name, ' '),
            'organisation' => $attendee->organisation,
            'consent_text' => $consentText,
        ]);
    }

    /** The attendee agreed, on the rep's device. */
    public function confirm(Request $request, Partner $partner, Event $event, string $scanId, ConsentService $consent): JsonResponse
    {
        $eventPartner = $this->eventPartner($partner, $event);
        $this->requireFeature($eventPartner, PartnerFeature::LeadsScan);

        // Checked before it's used up, so someone else's attempt can't spoil
        // the rep's real scan.
        $pending = Cache::get($this->cacheKey($scanId));

        abort_unless($this->belongsTo($pending, $partner, $event, $request), 404);
        abort_unless(Cache::pull($this->cacheKey($scanId)), 404); // single use

        $registration = EventRegistration::with('user')->findOrFail($pending['registration_id']);
        $attendee = $registration->user;

        $consent->grant(
            $attendee,
            ConsentType::PartnerContact,
            $event,
            source: 'badge_scan',
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
            effectiveImmediately: $attendee->hasVerifiedEmail(),
            partner: $partner,
            capturedBy: $request->user(),
        );

        Audit::record('partner.lead_scanned', subject: $partner, eventId: $event->id, after: ['registration_id' => $registration->id]);

        return response()->json(['message' => 'Thank you! They’re now in your leads.']);
    }

    /** The attendee said no: forget the scan, store nothing. */
    public function decline(Request $request, Partner $partner, Event $event, string $scanId): JsonResponse
    {
        $this->eventPartner($partner, $event);

        if ($this->belongsTo(Cache::get($this->cacheKey($scanId)), $partner, $event, $request)) {
            Cache::forget($this->cacheKey($scanId));
        }

        return response()->json(['message' => 'No problem. Nothing was shared.']);
    }

    /** A pending scan is answered only on the device of the rep who made it. */
    private function belongsTo(?array $pending, Partner $partner, Event $event, Request $request): bool
    {
        return $pending
            && $pending['partner_id'] === $partner->id
            && $pending['event_id'] === $event->id
            && $pending['rep_id'] === $request->user()->id;
    }

    private function cacheKey(string $scanId): string
    {
        return 'partner-scan:'.$scanId;
    }
}
