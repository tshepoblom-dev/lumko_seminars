<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesEventWorld;
use Tests\TestCase;

/**
 * "Profile & password" (the revived Breeze profile page): name, sign-in
 * email, password and — for attendees — anonymising account deletion.
 */
class ProfileTest extends TestCase
{
    use CreatesEventWorld;
    use RefreshDatabase;

    public function test_profile_page_is_displayed_for_an_attendee(): void
    {
        $user = User::factory()->create(['role' => UserRole::Attendee, 'first_name' => 'Thandi']);

        $this->actingAs($user)->get('/profile')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Profile/Edit')
                ->where('profile.first_name', 'Thandi')
                ->where('isStaff', false)
            );
    }

    public function test_profile_page_is_displayed_for_staff(): void
    {
        $this->actingAs($this->makeSuperAdmin())->get('/profile')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('isStaff', true));
    }

    public function test_an_unverified_user_can_still_reach_their_profile(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->get('/profile')->assertOk();
    }

    public function test_profile_information_can_be_updated(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch('/profile', [
                'first_name' => 'Test',
                'last_name' => 'User',
                'email' => 'test@example.com',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $user->refresh();

        $this->assertSame('Test', $user->first_name);
        $this->assertSame('User', $user->last_name);
        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch('/profile', [
                'first_name' => 'Test',
                'last_name' => 'User',
                'email' => $user->email,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $this->assertNotNull($user->refresh()->email_verified_at);
        Notification::assertNothingSent();
    }

    public function test_the_email_must_not_belong_to_someone_else(): void
    {
        $other = User::factory()->create(['email' => 'taken@example.com']);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch('/profile', ['first_name' => 'A', 'last_name' => 'B', 'email' => $other->email])
            ->assertSessionHasErrors('email');
    }

    public function test_an_attendee_deleting_their_account_is_anonymised_not_hard_deleted(): void
    {
        $event = $this->makeEvent();
        $registration = $this->registerAttendee($event);
        $user = $registration->user;

        $this->actingAs($user)
            ->delete('/profile', ['password' => 'password'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('home'));

        $this->assertGuest();

        $user->refresh();
        $this->assertStringEndsWith('@invalid', $user->email);
        $this->assertSame('Deleted user', $user->name);
        // Records needed for compliance survive, now without personal data.
        $this->assertModelExists($registration);
    }

    public function test_correct_password_must_be_provided_to_delete_account(): void
    {
        $user = User::factory()->create();
        $email = $user->email;

        $this->actingAs($user)
            ->from('/profile')
            ->delete('/profile', ['password' => 'wrong-password'])
            ->assertSessionHasErrors('password')
            ->assertRedirect('/profile');

        $this->assertSame($email, $user->fresh()->email);
        $this->assertAuthenticatedAs($user);
    }

    public function test_staff_cannot_delete_their_own_account_here(): void
    {
        $admin = $this->makeSuperAdmin();
        $email = $admin->email;

        $this->actingAs($admin)
            ->delete('/profile', ['password' => 'password'])
            ->assertForbidden();

        $this->assertSame($email, $admin->fresh()->email);
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get('/profile')->assertRedirect(route('login'));
    }
}
