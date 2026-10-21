<?php

namespace App\Http\Controllers\Public;

use App\Actions\SendComplimentaryTicketOnConfirmation;
use App\Http\Controllers\Controller;
use App\Services\ConsentService;
use App\Support\HomeRedirect;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * B2 — the destination of the ConfirmEmailAndSetPassword link. Reuses
 * Laravel's password-broker token (same table/expiry as "forgot
 * password") rather than a bespoke signed-URL scheme, since the
 * requirements are identical: a time-limited, single-use, email-bound
 * token that lets someone who isn't authenticated set a password.
 */
class ConfirmRegistrationController extends Controller
{
    public function create(Request $request): Response
    {
        return Inertia::render('Public/ConfirmRegistration', [
            'email' => $request->query('email', ''),
            'token' => $request->route('token'),
        ]);
    }

    public function store(Request $request, ConsentService $consentService, SendComplimentaryTicketOnConfirmation $complimentaryTickets): RedirectResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $newlyVerified = null;

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user) use ($consentService, &$newlyVerified) {
                $user->forceFill(['password' => Hash::make(request('password'))])->save();

                if (! $user->hasVerifiedEmail()) {
                    $user->markEmailAsVerified();
                    event(new Verified($user));
                    $newlyVerified = $user;
                }

                $consentService->markPendingEffective($user);

                Auth::login($user);
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => [trans($status)]]);
        }

        // A complimentary ticket from an invitation code: its PDF goes out
        // now that the address is proven.
        if ($newlyVerified) {
            $complimentaryTickets->send($newlyVerified);
        }

        return redirect(HomeRedirect::for(Auth::user()))->with('success', "You're all set — welcome!");
    }
}
