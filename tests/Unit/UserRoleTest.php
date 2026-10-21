<?php

namespace Tests\Unit;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserRoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_labels(): void
    {
        $this->assertSame('Super Admin', UserRole::SuperAdmin->label());
        $this->assertSame('Event Manager', UserRole::EventManager->label());
        $this->assertSame('Attendee', UserRole::Attendee->label());
    }

    public function test_staff_roles(): void
    {
        $this->assertTrue(UserRole::SuperAdmin->isStaff());
        $this->assertTrue(UserRole::EventManager->isStaff());
        $this->assertFalse(UserRole::Attendee->isStaff());
    }

    public function test_user_role_helpers(): void
    {
        $admin = User::factory()->make(['role' => UserRole::SuperAdmin]);
        $manager = User::factory()->make(['role' => UserRole::EventManager]);
        $attendee = User::factory()->make(['role' => UserRole::Attendee]);

        $this->assertTrue($admin->isSuperAdmin());
        $this->assertTrue($admin->isStaff());

        $this->assertTrue($manager->isEventManager());
        $this->assertTrue($manager->isStaff());

        $this->assertTrue($attendee->isAttendee());
        $this->assertFalse($attendee->isStaff());
    }
}
