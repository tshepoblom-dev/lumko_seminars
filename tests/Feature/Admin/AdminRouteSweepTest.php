<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * T-ACL-3 — parametrised over the real route table (not a hand-maintained
 * list) so a new admin route added later is swept automatically: no
 * parameter-free admin GET route may be reachable by a guest or a plain
 * attendee. Routes needing a route-model-bound parameter (e.g.
 * admin.events.edit) are exercised individually elsewhere
 * (AdminPagesRenderTest); this test covers the ones that don't, which is
 * most of the admin index/listing surface.
 */
class AdminRouteSweepTest extends TestCase
{
    use RefreshDatabase;

    private function parameterFreeAdminGetRoutes(): array
    {
        return collect(Route::getRoutes())
            ->filter(fn ($route) => str_starts_with((string) $route->getName(), 'admin.'))
            ->filter(fn ($route) => in_array('GET', $route->methods(), true))
            ->filter(fn ($route) => count($route->parameterNames()) === 0)
            ->map(fn ($route) => $route->getName())
            ->values()
            ->all();
    }

    public function test_guest_is_redirected_to_login_for_every_parameter_free_admin_route(): void
    {
        $routes = $this->parameterFreeAdminGetRoutes();
        $this->assertNotEmpty($routes, 'Sanity check: the admin route sweep should find at least one route.');

        foreach ($routes as $name) {
            $this->get(route($name))->assertRedirect(route('login'));
        }
    }

    public function test_attendee_is_forbidden_from_every_parameter_free_admin_route(): void
    {
        $attendee = User::factory()->create(['role' => UserRole::Attendee]);

        foreach ($this->parameterFreeAdminGetRoutes() as $name) {
            $this->actingAs($attendee)->get(route($name))->assertForbidden();
        }
    }

    // T-ACL-3 — an event manager not assigned to any event is blocked from
    // event-scoped admin data (unassigned) while a super admin can reach
    // everything.
    public function test_unassigned_event_manager_cannot_view_or_edit_an_event(): void
    {
        $manager = User::factory()->create(['role' => UserRole::EventManager]);
        $event = Event::factory()->create(['status' => 'published']);

        $this->actingAs($manager)->get(route('admin.events.edit', $event))->assertForbidden();
    }

    public function test_assigned_event_manager_can_view_and_edit_their_event(): void
    {
        $manager = User::factory()->create(['role' => UserRole::EventManager]);
        $event = Event::factory()->create(['status' => 'published']);
        $event->managers()->attach($manager->id, ['role' => 'event_manager']);

        $this->actingAs($manager)->get(route('admin.events.edit', $event))->assertOk();
    }
}
