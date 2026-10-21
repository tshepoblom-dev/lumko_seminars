<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Inertia\Inertia;
use Inertia\Response;

class PasswordResetLinkController extends Controller
{
    /**
     * Display the password reset link request view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/ForgotPassword', [
            'status' => session('status'),
        ]);
    }

    /**
     * Handle an incoming password reset link request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        // Sent straight away (not queued): the person is waiting for it. A
        // mail-server failure is reported and shown as a retry message
        // rather than a 500.
        try {
            $status = Password::sendResetLink(
                $request->only('email')
            );
        } catch (TransportExceptionInterface $e) {
            report($e);

            throw ValidationException::withMessages([
                'email' => ['We couldn\'t send the email just now. Please try again in a few minutes.'],
            ]);
        }

        // B2 — the same answer whether or not an account exists (or was
        // asked for a link a minute ago), like registration: the form must
        // not reveal who has an account.
        if (in_array($status, [Password::RESET_LINK_SENT, Password::INVALID_USER, Password::RESET_THROTTLED], true)) {
            return back()->with('status', 'If an account exists for that address, we\'ve emailed a link to reset the password. It can take a few minutes, so check your spam folder too.');
        }

        throw ValidationException::withMessages([
            'email' => [trans($status)],
        ]);
    }
}
