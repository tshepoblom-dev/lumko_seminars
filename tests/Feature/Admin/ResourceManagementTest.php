<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\Event;
use App\Models\Resource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ResourceManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_upload_a_resource_unpublished_by_default(): void
    {
        Storage::fake('local');

        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $event = Event::factory()->create();

        $response = $this->actingAs($admin)->post(route('admin.resources.store', $event), [
            'event_id' => $event->id,
            'title' => 'Speaker slides',
            'category' => 'during_event',
            'file' => UploadedFile::fake()->create('slides.pdf', 500, 'application/pdf'),
        ]);

        $response->assertRedirect(route('admin.resources.index', $event));
        $this->assertDatabaseHas('resources', ['title' => 'Speaker slides', 'is_published' => false]);
    }

    public function test_resource_requires_file_or_external_url(): void
    {
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $event = Event::factory()->create();

        $response = $this->actingAs($admin)->post(route('admin.resources.store', $event), [
            'event_id' => $event->id,
            'title' => 'Nothing attached',
            'category' => 'before_event',
        ]);

        $response->assertSessionHasErrors('file');
    }

    public function test_admin_can_publish_and_unpublish_a_resource(): void
    {
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $resource = Resource::factory()->create(['is_published' => false]);

        $this->actingAs($admin)->post(route('admin.resources.publish', [$resource->event_id, $resource->id]));
        $this->assertTrue($resource->fresh()->is_published);

        $this->actingAs($admin)->post(route('admin.resources.unpublish', [$resource->event_id, $resource->id]));
        $this->assertFalse($resource->fresh()->is_published);
    }
}
