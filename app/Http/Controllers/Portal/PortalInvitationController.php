<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\PortalInvitation;
use App\Services\PortalInvitationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * The destination of a portal invitation email. Three cases:
 * - nobody has an account with the invited address: choose a name and
 *   password, which creates the account (already verified) and links it;
 * - an account exists but nobody is signed in: log in first, then come
 *   back here (url.intended) and confirm;
 * - signed in as the invited address: confirm. Signed in as someone else:
 *   told so, never linked.
 */
class PortalInvitationController extends Controller
{
    public function __construct(private PortalInvitationService $invitations) {}

    public function show(Request $request, string $token): SymfonyResponse
    {
        $invitation = $this->invitations->resolve($token);
        $mode = $this->mode($request, $invitation);

        if ($mode === 'login') {
            $request->session()->put('url.intended', $request->url());
        }

        $response = Inertia::render('Portal/AcceptInvitation', [
            'token' => $token,
            'kind' => $invitation->kind,
            'subjectName' => $invitation->subjectName(),
            'email' => $invitation->email,
            'mode' => $mode,
            'suggestedName' => $invitation->kind === PortalInvitation::KIND_SPEAKER
                ? $this->splitName((string) $invitation->speaker?->name)
                : ['first' => '', 'last' => ''],
        ])->toResponse($request);

        // The URL carries the credential: keep it out of indexes, caches and referrers.
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }

    public function store(Request $request, string $token): RedirectResponse
    {
        $invitation = $this->invitations->resolve($token);
        $mode = $this->mode($request, $invitation);

        if ($mode === 'new') {
            $data = $request->validate([
                'first_name' => ['required', 'string', 'max:100'],
                'last_name' => ['required', 'string', 'max:100'],
                'password' => ['required', 'confirmed', Rules\Password::defaults()],
            ]);

            $user = $this->invitations->acceptAsNewUser($invitation, $data['first_name'], $data['last_name'], $data['password']);
            Auth::login($user);
            $request->session()->regenerate();
        } elseif ($mode === 'confirm') {
            $this->invitations->acceptAsExistingUser($invitation, $request->user());
        } else {
            return redirect()->route('portal-invitation.show', $token);
        }

        $destination = $invitation->kind === PortalInvitation::KIND_SPEAKER
            ? route('speaker.dashboard')
            : route('partner.dashboard', $invitation->partner);

        return redirect($destination)->with('success', 'Welcome! Your portal is ready.');
    }

    /** @return 'new'|'login'|'confirm'|'wrong_account' */
    private function mode(Request $request, PortalInvitation $invitation): string
    {
        $user = $request->user();

        if ($user) {
            return strtolower($user->email) === $invitation->email ? 'confirm' : 'wrong_account';
        }

        return $this->invitations->existingUserFor($invitation) ? 'login' : 'new';
    }

    /** @return array{first: string, last: string} */
    private function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name), 2) ?: [];

        return ['first' => $parts[0] ?? '', 'last' => $parts[1] ?? ''];
    }
}
