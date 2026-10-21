<?php

namespace App\Http\Controllers;

use App\Actions\AccountAnonymiser;
use App\Http\Requests\ProfileUpdateRequest;
use App\Support\Audit;
use Illuminate\Auth\Events\Logout;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Profile & password": the signed-in person's own name, sign-in email,
 * password (PUT /password, Auth\PasswordController) and, for attendees,
 * account deletion. Event details, mobile, organisation and consent
 * choices stay on /account (Public\PreferencesController).
 */
class ProfileController extends Controller
{
    public function edit(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Profile/Edit', [
            'profile' => [
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => $user->email,
                'email_verified' => $user->hasVerifiedEmail(),
            ],
            'mustVerifyEmail' => $user instanceof MustVerifyEmail,
            'status' => session('status'),
            'isStaff' => $user->isStaff(),
        ]);
    }

    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validated();

        $user->fill([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'name' => trim($data['first_name'].' '.$data['last_name']),
            'email' => $data['email'],
        ]);

        $emailChanged = $user->isDirty('email');
        $previousEmail = $user->getOriginal('email');

        if ($emailChanged) {
            // A new address has to be proven again before it is trusted.
            $user->email_verified_at = null;
        }

        $user->save();

        if ($emailChanged) {
            Audit::record('profile.email_changed', subject: $user, actor: $user, before: ['email' => $previousEmail], after: ['email' => $user->email]);
            $user->sendEmailVerificationNotification();

            return redirect()->route('profile.edit')
                ->with('info', "Check {$user->email} for a link to confirm your new address.");
        }

        return redirect()->route('profile.edit')->with('success', 'Profile updated.');
    }

    /**
     * B12 — deleting an account is anonymisation (AccountAnonymiser), the
     * same as "Delete account" on /account. Staff accounts are removed by
     * another super admin, never self-served here, so the admin area can't
     * be locked out by accident.
     */
    public function destroy(Request $request, AccountAnonymiser $anonymiser): RedirectResponse
    {
        $user = $request->user();
        abort_if($user->isStaff(), 403, 'Staff accounts cannot be deleted from here.');

        $request->validate(['password' => ['required', 'current_password']]);

        $anonymiser->anonymise($user, actor: $user);

        Auth::guard('web')->logout();
        event(new Logout('web', $user));

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home')->with('success', 'Your account has been deleted.');
    }
}
