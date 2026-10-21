<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Notifications\TicketConfirmed;
use App\Services\VoucherService;
use App\Support\VoucherCode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * /redeem — an invitation code for someone who is already signed in: it
 * upgrades their registration for the code's event (online → in person,
 * payment waived, ticket confirmed). Not registered yet → the registration
 * form, with the code pre-filled. The GET only pre-fills; only the POST
 * uses a code up.
 */
class VoucherRedemptionController extends Controller
{
    public function __construct(private VoucherService $vouchers) {}

    public function show(Request $request): Response
    {
        $code = $request->query('code');

        return Inertia::render('Public/RedeemVoucher', [
            'code' => is_string($code) && preg_match('/^[A-Za-z0-9 -]{1,32}$/', trim($code)) ? trim($code) : '',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(
            ['voucher_code' => ['required', 'string', 'max:32']],
            ['voucher_code.required' => 'Please enter the code from your invitation.'],
        );
        $user = $request->user();

        $voucher = $this->vouchers->resolve($data['voucher_code'], $user->email);
        $registration = $user->registrations()->where('event_id', $voucher->event_id)->first();

        // Not registered for that event yet: the registration form takes
        // the code (pre-filled — Register.vue says so), not this page.
        if (! $registration) {
            return redirect()->route('event.register', ['code' => VoucherCode::format($data['voucher_code'])]);
        }

        $ticket = $this->vouchers->redeem($voucher, $registration, $user);
        $user->notify(new TicketConfirmed($ticket));

        return redirect()->route('ticket.show')
            ->with('success', 'Your invitation code has been applied — your complimentary ticket is confirmed.');
    }
}
