<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Support\HomeRedirect;
use App\Support\Legal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Privacy Policy and Terms & Conditions (public), and the one-time
 * "please accept" step for signed-in accounts (EnsureLegalAccepted).
 */
class LegalController extends Controller
{
    public function privacy(): Response
    {
        return Inertia::render('Public/Legal/Privacy', ['facts' => Legal::facts()]);
    }

    public function terms(): Response
    {
        return Inertia::render('Public/Legal/Terms', ['facts' => Legal::facts()]);
    }

    public function showAccept(Request $request): Response|RedirectResponse
    {
        if (Legal::hasAcceptedCurrent($request->user())) {
            return redirect()->intended(HomeRedirect::for($request->user()));
        }

        $hasAcceptedBefore = $request->user()->legalAcceptances()->exists();

        return Inertia::render('Public/Legal/Accept', [
            'facts' => Legal::facts(),
            'updated' => $hasAcceptedBefore,
        ]);
    }

    public function accept(Request $request): RedirectResponse
    {
        $request->validate(['accept_legal' => ['accepted']], [
            'accept_legal.accepted' => 'Please tick the box to accept the Terms & Conditions and the Privacy Policy.',
        ]);

        Legal::recordAcceptance($request->user(), 'prompt', $request);

        return redirect()->intended(HomeRedirect::for($request->user()));
    }
}
