<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** T-AUD-1 */
class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_event_manager_cannot_view_the_audit_log(): void
    {
        $manager = User::factory()->create(['role' => UserRole::EventManager]);

        $response = $this->actingAs($manager)->get(route('admin.audit.index'));

        $response->assertForbidden();
    }

    public function test_super_admin_can_view_and_filter_the_audit_log(): void
    {
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        Audit::record('payment.approved', actor: $admin);
        Audit::record('event.archived', actor: $admin);

        $response = $this->actingAs($admin)->get(route('admin.audit.index', ['action' => 'payment']));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Audit/Index')
            ->has('logs.data', 1)
            ->where('logs.data.0.action', 'payment.approved')
        );
    }
}
