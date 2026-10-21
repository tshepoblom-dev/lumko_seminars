<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesEventWorld;
use Tests\TestCase;

class AssignEventManagerCommandTest extends TestCase
{
    use CreatesEventWorld, RefreshDatabase;

    public function test_assigning_a_manager_lets_them_see_the_event_and_is_audited(): void
    {
        $event = $this->makeEvent(['slug' => 'the-power-of-data']);
        $manager = User::factory()->create(['role' => UserRole::EventManager, 'email' => 'manager@example.com']);

        $this->assertFalse($this->actingAs($manager)->get(route('admin.attendees.index', $event->id))->isOk());

        $this->artisan('lumko:assign-manager', ['email' => 'Manager@Example.com', 'event' => 'the-power-of-data'])
            ->assertSuccessful();

        $this->actingAs($manager)->get(route('admin.attendees.index', $event->id))->assertOk();
        $this->assertSame(1, AuditLog::where('action', 'event.manager_assigned')->where('event_id', $event->id)->count());
    }

    public function test_assigning_twice_does_not_duplicate_the_assignment(): void
    {
        $event = $this->makeEvent();
        $manager = User::factory()->create(['role' => UserRole::EventManager]);

        $this->artisan('lumko:assign-manager', ['email' => $manager->email, 'event' => (string) $event->id])->assertSuccessful();
        $this->artisan('lumko:assign-manager', ['email' => $manager->email, 'event' => (string) $event->id])->assertSuccessful();

        $this->assertSame(1, $event->managers()->where('users.id', $manager->id)->count());
    }

    public function test_remove_unassigns_the_manager(): void
    {
        $event = $this->makeEvent();
        $manager = $this->makeManagerFor($event);

        $this->artisan('lumko:assign-manager', ['email' => $manager->email, 'event' => (string) $event->id, '--remove' => true])
            ->assertSuccessful();

        $this->assertFalse($event->managers()->where('users.id', $manager->id)->exists());
        $this->actingAs($manager)->get(route('admin.attendees.index', $event->id))->assertForbidden();
    }

    public function test_refuses_accounts_that_are_not_event_managers(): void
    {
        $event = $this->makeEvent();
        $attendee = User::factory()->create(['role' => UserRole::Attendee]);

        $this->artisan('lumko:assign-manager', ['email' => $attendee->email, 'event' => (string) $event->id])
            ->assertFailed();

        $this->assertSame(0, $event->managers()->count());
    }

    public function test_refuses_an_unknown_user_or_event(): void
    {
        $event = $this->makeEvent();
        $manager = User::factory()->create(['role' => UserRole::EventManager]);

        $this->artisan('lumko:assign-manager', ['email' => 'nobody@example.com', 'event' => (string) $event->id])->assertFailed();
        $this->artisan('lumko:assign-manager', ['email' => $manager->email, 'event' => 'no-such-event'])->assertFailed();
    }
}
