<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LivestreamSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_set_agora_as_primary_and_youtube_as_secondary(): void
    {
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $event = Event::factory()->create();

        $response = $this->actingAs($admin)->put(route('admin.events.livestream.update', $event), [
            'livestream_provider' => 'agora',
            'livestream_channel_name' => 'power-of-data-main',
            'livestream_secondary_provider' => 'youtube',
            'livestream_secondary_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'live_lead_minutes' => 20,
        ]);

        $response->assertRedirect();
        $event->refresh();
        $this->assertSame('agora', $event->livestream_provider->value);
        $this->assertSame('youtube', $event->livestream_secondary_provider->value);
        $this->assertSame(20, $event->live_lead_minutes);
        $this->assertDatabaseHas('audit_logs', ['action' => 'event.livestream_updated', 'subject_id' => $event->id]);
    }

    // C3 — a URL that doesn't actually point at the claimed provider's domain is rejected.
    public function test_youtube_url_must_be_a_youtube_host(): void
    {
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $event = Event::factory()->create();

        $response = $this->actingAs($admin)->put(route('admin.events.livestream.update', $event), [
            'livestream_provider' => 'youtube',
            'livestream_url' => 'https://example.com/not-youtube',
        ]);

        $response->assertSessionHasErrors('livestream_url');
    }

    public function test_http_livestream_url_is_rejected(): void
    {
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $event = Event::factory()->create();

        $response = $this->actingAs($admin)->put(route('admin.events.livestream.update', $event), [
            'livestream_provider' => 'youtube',
            'livestream_url' => 'http://www.youtube.com/watch?v=dQw4w9WgXcQ',
        ]);

        $response->assertSessionHasErrors('livestream_url');
    }

    public function test_unassigned_event_manager_cannot_update_livestream_settings(): void
    {
        $manager = User::factory()->create(['role' => UserRole::EventManager]);
        $event = Event::factory()->create();

        $response = $this->actingAs($manager)->put(route('admin.events.livestream.update', $event), [
            'livestream_provider' => 'agora',
            'livestream_channel_name' => 'main',
        ]);

        $response->assertForbidden();
    }
}
