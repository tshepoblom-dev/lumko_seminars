<?php

namespace Tests\Feature\Public;

use App\Enums\UserRole;
use App\Models\Event;
use App\Models\Feedback;
use App\Models\User;
use App\Notifications\AccountAnonymised;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** T-DEL-4 */
class AccountDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleting_account_anonymises_pii_and_logs_out(): void
    {
        Notification::fake();

        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $event = Event::factory()->create(['status' => 'published']);
        $user = User::factory()->create([
            'role' => UserRole::Attendee, 'password' => bcrypt('correct-password'),
            'first_name' => 'Real', 'last_name' => 'Name', 'organisation' => 'Real Org', 'mobile' => '0821234567',
        ]);
        $registration = $user->registrations()->create([
            'event_id' => $event->id, 'attendance_mode' => 'online', 'status' => 'registered',
            'organisation_challenge' => 'Our real internal challenge text.',
        ]);
        Feedback::create(['event_id' => $event->id, 'user_id' => $user->id, 'rating' => 4, 'biggest_takeaway' => 'A real personal takeaway.']);

        $response = $this->actingAs($user)->delete(route('attendee.preferences.destroy'), [
            'password' => 'correct-password',
        ]);

        $response->assertRedirect(route('home'));
        $this->assertGuest();

        $user->refresh();
        $this->assertSame('Deleted user', $user->name);
        $this->assertStringContainsString('@invalid', $user->email);
        $this->assertNull($user->mobile);
        $this->assertNull($user->organisation);

        // Registration row kept (financial/compliance evidence), free text wiped.
        $this->assertDatabaseHas('event_registrations', ['id' => $registration->id, 'organisation_challenge' => null]);
        $this->assertDatabaseHas('feedback', ['user_id' => $user->id, 'biggest_takeaway' => null]);

        $this->assertDatabaseHas('audit_logs', ['action' => 'user.anonymised', 'subject_id' => $user->id]);
        Notification::assertSentTo($admin, AccountAnonymised::class);
    }

    public function test_deleting_account_requires_correct_password(): void
    {
        $user = User::factory()->create(['password' => bcrypt('correct-password')]);

        $response = $this->actingAs($user)->delete(route('attendee.preferences.destroy'), [
            'password' => 'wrong-password',
        ]);

        $response->assertSessionHasErrors('password');
        $this->assertNotSame('Deleted user', $user->fresh()->name);
        $this->assertAuthenticated();
    }
}
