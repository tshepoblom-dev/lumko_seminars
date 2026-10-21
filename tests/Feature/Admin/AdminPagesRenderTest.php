<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * T-BOOT-1: every admin page must render for staff. Guards against the missing
 * AuthorizesRequests trait (500s on authorize()) and other boot-level breakage.
 */
class AdminPagesRenderTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_can_load_every_admin_index_page(): void
    {
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $event = Event::factory()->create([
            'status' => 'published',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHours(2),
        ]);

        $urls = [
            // Cross-event areas
            route('admin.dashboard'),
            route('admin.events.index'),
            route('admin.events.create'),
            route('admin.speakers.index'),
            route('admin.speakers.create'),
            route('admin.speakers.create', ['event' => $event->id]),
            route('admin.audit.index'),
            route('admin.analytics.index'),
            route('admin.platform-communications.index'),
            route('admin.platform-communications.create'),

            // The event workspace
            route('admin.events.show', $event),
            route('admin.events.edit', $event),
            route('admin.programme-sessions.index', $event),
            route('admin.programme-sessions.create', $event),
            route('admin.events.speakers.index', $event),
            route('admin.attendees.index', $event),
            route('admin.payments.index', $event),
            route('admin.vouchers.index', $event),
            route('admin.check-in.index', $event),
            route('admin.announcements.index', $event),
            route('admin.announcements.create', $event),
            route('admin.communications.index', $event),
            route('admin.communications.create', $event),
            route('admin.communications.create', [$event, 'mode' => 'selected']),
            route('admin.resources.index', $event),
            route('admin.resources.create', $event),
            route('admin.live.control-room', $event),
            route('admin.live.broadcast.show', $event),
            route('admin.live.questions.index', $event),
            route('admin.live.polls.index', $event),
            route('admin.live.polls.create', $event),
            route('admin.feedback.index', $event),
            route('admin.engagement.show', $event),
        ];

        foreach ($urls as $url) {
            $this->actingAs($admin)->get($url)->assertOk();
        }
    }
}
