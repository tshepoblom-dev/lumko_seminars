<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreVoucherRequest;
use App\Models\Event;
use App\Models\User;
use App\Models\Voucher;
use App\Services\VoucherService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Event → Vouchers: honorary invitation codes, each good for one
 * complimentary in-person ticket. Issue (optionally emailing the
 * invitation), re-send, revoke. Anyone who can manage the event can do all
 * three — the same people who can approve its payments — and every action
 * is audited (VoucherService).
 */
class VoucherController extends Controller
{
    private const STATUS_LABELS = [
        'unused' => 'Not emailed',
        'sent' => 'Emailed',
        'redeemed' => 'Redeemed',
        'revoked' => 'Revoked',
        'closed' => 'Registration closed',
    ];

    public function __construct(private VoucherService $vouchers) {}

    public function index(Event $event): Response
    {
        $this->authorize('view', $event);

        $vouchers = $event->vouchers()
            ->with(['registration.user', 'creator:id,name'])
            ->latest()
            ->latest('id')
            ->get()
            ->each(fn (Voucher $voucher) => $voucher->setRelation('event', $event));

        $statuses = $vouchers->map(fn (Voucher $voucher) => $voucher->status());

        return Inertia::render('Admin/Vouchers/Index', [
            'registrationOpen' => $event->isRegistrationOpen(),
            'eventIsDraft' => $event->status === 'draft',
            'summary' => [
                'issued' => $vouchers->count(),
                'redeemed' => $statuses->filter(fn ($s) => $s === 'redeemed')->count(),
                'outstanding' => $statuses->filter(fn ($s) => in_array($s, ['unused', 'sent'], true))->count(),
                'revoked' => $statuses->filter(fn ($s) => $s === 'revoked')->count(),
            ],
            'vouchers' => $vouchers->map(function (Voucher $voucher) use ($event) {
                $status = $voucher->status();
                $attendee = $voucher->registration?->user;

                return [
                    'id' => $voucher->id,
                    'code' => $voucher->code,
                    'recipient_name' => $voucher->recipient_name,
                    'recipient_email' => $voucher->recipient_email,
                    'email_locked' => $voucher->email_locked,
                    'note' => $voucher->note,
                    'status' => $status,
                    'status_label' => self::STATUS_LABELS[$status],
                    'created_at' => $voucher->created_at->toIso8601String(),
                    'created_by' => $voucher->creator?->name,
                    'sent_at' => $voucher->sent_at?->toIso8601String(),
                    'redeemed_at' => $voucher->redeemed_at?->toIso8601String(),
                    'redeemed_by' => $attendee ? [
                        'name' => trim($attendee->first_name.' '.$attendee->last_name) ?: $attendee->name,
                        'email' => $attendee->email,
                        // A guest's account isn't set up until they use the
                        // confirm-email link; staff can resend it from the
                        // attendee's page.
                        'account_ready' => $attendee->hasVerifiedEmail(),
                        'url' => route('admin.attendees.show', ['event' => $event->id, 'registration' => $voucher->registration->id]),
                    ] : null,
                    'can_send' => filled($voucher->recipient_email) && in_array($status, ['unused', 'sent'], true),
                    'can_revoke' => in_array($status, ['unused', 'sent'], true),
                ];
            })->values(),
        ]);
    }

    public function store(StoreVoucherRequest $request, Event $event): RedirectResponse
    {
        $voucher = $this->vouchers->issue($event, $request->validated(), $request->user());

        if (! $request->boolean('send_now')) {
            return back()->with('success', "Invitation code created for {$voucher->recipient_name}.");
        }

        if (! $event->isRegistrationOpen()) {
            return back()->with('info', "Invitation code created for {$voucher->recipient_name}, but not emailed: registration isn't open, so the link in the email wouldn't work yet.");
        }

        return $this->deliver($voucher, $request->user(), justCreated: true);
    }

    public function send(Request $request, Event $event, Voucher $voucher): RedirectResponse
    {
        $this->authorize('update', $event);

        if (blank($voucher->recipient_email)) {
            return back()->with('error', 'This code has no email address. Copy the code and share it yourself.');
        }

        if ($voucher->redeemed_at || $voucher->revoked_at) {
            return back()->with('error', 'This code has already been redeemed or revoked, so there is nothing to send.');
        }

        if (! $event->isRegistrationOpen()) {
            return back()->with('error', "Registration isn't open, so the link in the email wouldn't work yet.");
        }

        return $this->deliver($voucher, $request->user());
    }

    public function revoke(Request $request, Event $event, Voucher $voucher): RedirectResponse
    {
        $this->authorize('update', $event);

        if (! $this->vouchers->revoke($voucher, $request->user())) {
            return back()->with('error', $voucher->fresh()->redeemed_at
                ? 'This code has already been redeemed, so it can no longer be revoked.'
                : 'This code was already revoked.');
        }

        return back()->with('success', "The code for {$voucher->recipient_name} no longer works.");
    }

    /** Emails the invitation; on failure the code is kept (and can be copied or re-sent). */
    private function deliver(Voucher $voucher, User $actor, bool $justCreated = false): RedirectResponse
    {
        try {
            $this->vouchers->send($voucher, $actor);
        } catch (Throwable $e) {
            Log::warning('voucher.send_failed', ['voucher_id' => $voucher->id, 'error' => $e->getMessage()]);

            return back()->with('error', ($justCreated ? "Invitation code created for {$voucher->recipient_name}, but the" : 'The')
                .' email could not be sent. Copy the code to share it yourself, or try sending again.');
        }

        return back()->with('success', ($justCreated ? 'Invitation code created and emailed' : 'Invitation emailed')
            ." to {$voucher->recipient_name} ({$voucher->recipient_email}).");
    }
}
