<?php

namespace App\Http\Controllers\Public;

use App\Enums\ConsentType;
use App\Enums\PartnerFeature;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventPartner;
use App\Models\Partner;
use App\Models\PartnerConnection;
use App\Models\Resource;
use App\Notifications\PartnerEnquiryReceived;
use App\Services\ConsentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * A partner's page for attendees of one event, and the two ways an
 * attendee can choose to become that partner's lead: Connect (share my
 * details, optional note) and an enquiry (a message, with an explicit
 * consent tick). Either way the attendee sees the exact consent sentence,
 * and it is recorded in the ledger as `partner_contact`. Partners never
 * receive the attendee list.
 *
 * Everything here sits behind the `attendee` gate (registered for this
 * event, or a speaker on it). Withdrawing is on /account.
 */
class PartnerPageController extends Controller
{
    public function __construct(private ConsentService $consents) {}

    public function show(Request $request, Event $event, Partner $partner): Response
    {
        $eventPartner = $this->published($event, $partner);
        abort_unless($eventPartner->hasFeature(PartnerFeature::ProfilePage), 404);

        $user = $request->user();
        $connection = PartnerConnection::where(['user_id' => $user->id, 'event_id' => $event->id, 'partner_id' => $partner->id])
            ->active()->first();

        [, $consentText] = $this->consents->wording(ConsentType::PartnerContact, partner: $partner);
        $canConnect = ! $user->isStaff() && ! $partner->hasMember($user) && $event->acceptsPartnerConnections();

        return Inertia::render('Public/Partner', [
            'event' => ['id' => $event->id, 'name' => $event->name],
            'partner' => [
                'slug' => $partner->slug,
                'name' => $partner->name,
                'website' => $partner->website,
                'description' => $partner->description,
                'logo_url' => $partner->publicLogoUrl(),
                'logo_alt' => $partner->logo_alt,
                'blurb' => $eventPartner->blurb,
                'stand_label' => $eventPartner->stand_label,
                'tier' => $eventPartner->tier?->name,
            ],
            'offersConnect' => $eventPartner->hasFeature(PartnerFeature::LeadsConnect),
            'offersEnquiry' => $eventPartner->hasFeature(PartnerFeature::LeadsEnquiry),
            'canConnect' => $canConnect,
            'connected' => $connection ? ['since' => $connection->granted_at?->toIso8601String(), 'id' => $connection->id] : null,
            'consentText' => $consentText,
            'resources' => $eventPartner->hasFeature(PartnerFeature::Resources)
                ? Resource::where('event_id', $event->id)->where('partner_id', $partner->id)->published()->latest()->get()
                    ->map(fn (Resource $r) => [
                        'id' => $r->id,
                        'title' => $r->title,
                        'description' => $r->description,
                        'is_external' => (bool) $r->external_url,
                    ])
                : [],
        ]);
    }

    public function connect(Request $request, Event $event, Partner $partner): RedirectResponse
    {
        $eventPartner = $this->published($event, $partner);
        abort_unless($eventPartner->hasFeature(PartnerFeature::LeadsConnect), 404);
        $this->assertMayConnect($request, $event, $partner);

        $note = $request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null;
        $note = filled($note) ? trim($note) : null;

        if ($this->consents->isGranted($request->user(), ConsentType::PartnerContact, $event, partner: $partner)) {
            if ($note) {
                PartnerConnection::where(['user_id' => $request->user()->id, 'event_id' => $event->id, 'partner_id' => $partner->id])
                    ->update(['note' => $note]);
            }

            return back()->with('success', "You're already connected with {$partner->name}.");
        }

        $this->consents->grant(
            $request->user(), ConsentType::PartnerContact, $event,
            source: 'connect', ipAddress: $request->ip(), userAgent: substr((string) $request->userAgent(), 0, 500),
            effectiveImmediately: true, note: $note, partner: $partner,
        );

        return back()->with('success', "Done. {$partner->name} can now see your details and contact you.");
    }

    public function enquire(Request $request, Event $event, Partner $partner): RedirectResponse
    {
        $eventPartner = $this->published($event, $partner);
        abort_unless($eventPartner->hasFeature(PartnerFeature::LeadsEnquiry), 404);
        $this->assertMayConnect($request, $event, $partner);

        $data = $request->validate([
            'message' => ['required', 'string', 'max:1000'],
            'consent' => ['accepted'],
        ], ['consent.accepted' => 'Tick the box to share your details, so they can reply.']);

        $this->consents->grant(
            $request->user(), ConsentType::PartnerContact, $event,
            source: 'enquiry', ipAddress: $request->ip(), userAgent: substr((string) $request->userAgent(), 0, 500),
            effectiveImmediately: true, note: trim($data['message']), partner: $partner,
        );

        try {
            Notification::send($partner->members()->get(), new PartnerEnquiryReceived(
                $partner->name, $event->name, route('partner.events.leads.index', [$partner, $event]),
            ));
        } catch (Throwable $e) {
            // The enquiry is saved either way; the team sees it in the portal.
            Log::warning('partner.enquiry_notify_failed', ['partner_id' => $partner->id, 'error' => $e->getMessage()]);
        }

        return back()->with('success', "Sent. {$partner->name} will reply to you by email.");
    }

    /** Withdrawing works for any past event, so it sits outside the attendee gate. */
    public function withdraw(Request $request, PartnerConnection $partnerConnection): RedirectResponse
    {
        $user = $request->user();
        abort_unless($partnerConnection->user_id === $user->id, 404);

        if ($partnerConnection->isActive() && $partnerConnection->event && $partnerConnection->partner) {
            $this->consents->withdraw(
                $user, ConsentType::PartnerContact, $partnerConnection->event,
                source: 'account', ipAddress: $request->ip(), userAgent: substr((string) $request->userAgent(), 0, 500),
                partner: $partnerConnection->partner,
            );
        }

        return back()->with('success', 'Withdrawn. '.($partnerConnection->partner?->name ?? 'The partner').' can no longer see your details.');
    }

    private function published(Event $event, Partner $partner): EventPartner
    {
        $eventPartner = EventPartner::with('tier')->where('event_id', $event->id)->where('partner_id', $partner->id)
            ->where('is_published', true)->first();

        abort_unless($eventPartner, 404);

        return $eventPartner;
    }

    private function assertMayConnect(Request $request, Event $event, Partner $partner): void
    {
        $user = $request->user();

        // Staff pass the attendee gate to preview pages; they aren't leads.
        abort_if($user->isStaff(), 403, 'Staff accounts cannot connect with partners.');
        abort_if($partner->hasMember($user), 403, 'You are on this partner’s team.');

        if (! $event->acceptsPartnerConnections()) {
            abort(back()->with('error', 'Connecting with partners has closed for this event.'));
        }
    }
}
