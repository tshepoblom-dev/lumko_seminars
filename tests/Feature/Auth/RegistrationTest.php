<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Support\Legal;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

// Standalone sign-up: an account can be created without registering for an
// event; the event itself is registered for later from /attend.
class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        $this->get('/register')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Auth/Register'));
    }

    // Owner decision 2026-10-13: no account-only sign-up while an event is
    // open — the event form creates the account. (The other tests here run
    // with no event, when sign-up is the only way to make one.)
    public function test_sign_up_leads_to_the_event_form_while_an_event_is_open(): void
    {
        \App\Models\Event::factory()->create([
            'status' => 'published', 'starts_at' => now()->addWeek(), 'ends_at' => now()->addWeek()->addHours(2),
        ]);

        $this->get('/register')->assertRedirect(route('event.register'));

        $this->post('/register', [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'accept_legal' => '1',
        ])->assertRedirect(route('event.register'));

        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    public function test_new_users_can_register(): void
    {
        Event::fake([Registered::class]);

        $response = $this->post('/register', [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'Test@Example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'accept_legal' => '1',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('attendee.dashboard', absolute: false));

        $user = User::where('email', 'test@example.com')->firstOrFail();
        $this->assertSame('Test', $user->first_name);
        $this->assertSame('User', $user->last_name);
        $this->assertSame('Test User', $user->name);

        // The verification email still goes out.
        Event::assertDispatched(Registered::class);
    }

    public function test_sign_up_requires_accepting_the_terms_and_records_it(): void
    {
        $this->post('/register', [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'no-accept@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertSessionHasErrors('accept_legal');
        $this->assertGuest();

        $this->post('/register', [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'accepts@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'accept_legal' => '1',
        ]);

        $user = User::where('email', 'accepts@example.com')->firstOrFail();
        $this->assertTrue(Legal::hasAcceptedCurrent($user));
        $this->assertDatabaseHas('legal_acceptances', ['user_id' => $user->id, 'document' => 'terms', 'source' => 'signup']);
    }

    public function test_registration_requires_first_and_last_name(): void
    {
        $this->post('/register', [
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertSessionHasErrors(['first_name', 'last_name']);

        $this->assertGuest();
    }

    public function test_an_existing_email_cannot_register_again(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->post('/register', [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'taken@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertSessionHasErrors('email');
    }
}
