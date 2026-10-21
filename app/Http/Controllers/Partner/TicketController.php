<?php

namespace App\Http\Controllers\Partner;

use App\Enums\PartnerFeature;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Partner\Concerns\ResolvesPartnerEvent;
use App\Models\Event;
use App\Models\Partner;
use App\Models\Voucher;
use App\Services\VoucherService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Complimentary tickets a partner gives away (tier feature `comp_tickets`,
 * capped by the tier's count). Each is an ordinary invitation code
 * (VoucherService), emailed by the platform to the guest the partner
 * names, so it works like one staff issue. Revoked codes don't count
 * towards the cap.
 */
class TicketController extends Controller
{
    use ResolvesPartnerEvent;

    public function index(Partner $partner, Event $event): Response
    {
        $eventPartner = $this->eventPartner($partner, $event);
        $this->requireFeature($eventPartner, PartnerFeature::CompTickets);

        $vouchers = Voucher::where('event_id', $event->id)->where('partner_id', $partner->id)->latest()->get();

        return Inertia::render('Partner/Tickets', [
            ...$this->portalProps($partner, $event, $eventPartner),
            'cap' => $eventPartner->compTicketCap(),
            'used' => $vouchers->whereNull('revoked_at')->count(),
            'tickets' => $vouchers->map(fn (Voucher $v) => [
                'id' => $v->id,
                'recipient_name' => $v->recipient_name,
                'recipient_email' => $v->recipient_email,
                'status' => $v->status(),
                'sent_at' => $v->sent_at?->toIso8601String(),
                'created_at' => $v->created_at?->toIso8601String(),
            ]),
        ]);
    }

    public function store(Request $request, Partner $partner, Event $event, VoucherService $vouchers): RedirectResponse
    {
        $eventPartner = $this->eventPartner($partner, $event);
        $this->requireFeature($eventPartner, PartnerFeature::CompTickets);

        $data = $request->validate([
            'recipient_name' => ['required', 'string', 'max:255'],
            'recipient_email' => ['required', 'email:rfc', 'max:255'],
        ]);

        // Counted and issued under a lock on the event-partner row, so two
        // team members can't both take the last ticket.
        $voucher = DB::transaction(function () use ($eventPartner, $event, $partner, $data, $vouchers, $request) {
            $eventPartner->newQuery()->whereKey($eventPartner->id)->lockForUpdate()->first();

            $used = Voucher::where('event_id', $event->id)->where('partner_id', $partner->id)->whereNull('revoked_at')->count();

            if ($used >= $eventPartner->compTicketCap()) {
                return null;
            }

            return $vouchers->issue($event, [
                'partner_id' => $partner->id,
                'recipient_name' => $data['recipient_name'],
                'recipient_email' => $data['recipient_email'],
                // Locked to the guest's address: a partner's ticket is for that guest.
                'email_locked' => true,
                'note' => "Issued by partner {$partner->name}",
            ], $request->user());
        });

        if (! $voucher) {
            return back()->with('error', 'You have used all the complimentary tickets your partnership includes.');
        }

        try {
            $vouchers->send($voucher, $request->user());
        } catch (Throwable $e) {
            Log::warning('partner.ticket_send_failed', ['voucher_id' => $voucher->id, 'error' => $e->getMessage()]);

            return back()->with('error', 'The ticket was created but the email could not be sent. Please ask our team to resend it.');
        }

        return back()->with('success', "Ticket emailed to {$voucher->recipient_name}.");
    }
}
