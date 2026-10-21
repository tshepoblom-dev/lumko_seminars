<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\Legal;
use App\Support\RegistrationEvent;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     *
     * Owner decision 2026-10-13: while an event is open for registration
     * there is no account-only sign-up — people who made one never
     * finished the event form and were missing from the attendee list. The
     * event form creates the account. With no open event this page is the
     * only way to make one, so it stays.
     */
    public function create(): Response|RedirectResponse
    {
        if (RegistrationEvent::current()) {
            return redirect()->route('event.register');
        }

        return Inertia::render('Auth/Register');
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        if (RegistrationEvent::current()) {
            return redirect()->route('event.register')
                ->with('info', 'Register for the event here. Your account is created at the same time.');
        }

        $request->merge(['email' => strtolower((string) $request->input('email'))]);

        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'required|string|lowercase|email|max:255|unique:'.User::class,
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'accept_legal' => ['accepted'],
        ], [
            'accept_legal.accepted' => 'Please accept the Terms & Conditions and the Privacy Policy to create an account.',
        ]);

        $user = User::create([
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'name' => trim($validated['first_name'].' '.$validated['last_name']),
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
        ]);

        Legal::recordAcceptance($user, 'signup', $request);

        event(new Registered($user));

        Auth::login($user);

        // The dashboard shows the "not registered for an event yet" state
        // with a route into /attend.
        return redirect(route('attendee.dashboard', absolute: false));
    }
}
