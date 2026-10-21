<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\Event;
use App\Models\ProgrammeSession;
use App\Models\Speaker;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SpeakerPhotoUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_uploading_a_photo_stores_it_unapproved(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);

        $response = $this->actingAs($admin)->post(route('admin.speakers.store'), [
            'name' => 'Dr Herkulaas Combrink',
            'photo' => UploadedFile::fake()->image('speaker.jpg'),
            'photo_alt' => 'Portrait of Dr Herkulaas Combrink',
        ]);

        $response->assertRedirect(route('admin.speakers.index'));
        $speaker = Speaker::firstOrFail();
        $this->assertFalse($speaker->photo_approved);
        $this->assertSame('Portrait of Dr Herkulaas Combrink', $speaker->photo_alt);
        Storage::disk('public')->assertExists($speaker->photo_path);
    }

    public function test_photo_requires_alt_text(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);

        $response = $this->actingAs($admin)->post(route('admin.speakers.store'), [
            'name' => 'No Alt Text',
            'photo' => UploadedFile::fake()->image('speaker.jpg'),
        ]);

        $response->assertSessionHasErrors('photo_alt');
    }

    public function test_rejects_non_image_file(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);

        $response = $this->actingAs($admin)->post(route('admin.speakers.store'), [
            'name' => 'Bad Upload',
            'photo' => UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf'),
            'photo_alt' => 'n/a',
        ]);

        $response->assertSessionHasErrors('photo');
    }

    public function test_replacing_a_photo_deletes_the_old_one_and_resets_approval(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $speaker = Speaker::factory()->create([
            'photo_path' => 'speakers/old.jpg', 'photo_approved' => true,
        ]);
        Storage::disk('public')->put('speakers/old.jpg', 'fake-bytes');

        $response = $this->actingAs($admin)->put(route('admin.speakers.update', $speaker->id), [
            'name' => $speaker->name,
            'photo' => UploadedFile::fake()->image('new.jpg'),
            'photo_alt' => 'New photo',
            'photo_approved' => true,
        ]);

        $response->assertRedirect(route('admin.speakers.index'));
        $speaker->refresh();
        Storage::disk('public')->assertMissing('speakers/old.jpg');
        Storage::disk('public')->assertExists($speaker->photo_path);
        $this->assertNotSame('speakers/old.jpg', $speaker->photo_path);
        // Even though the request also sent photo_approved=true, a fresh
        // upload must always come back unapproved (D-Programme data spec).
        $this->assertFalse($speaker->photo_approved);
    }

    public function test_approving_an_existing_photo_without_a_new_upload(): void
    {
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $speaker = Speaker::factory()->create(['photo_path' => 'speakers/existing.jpg', 'photo_approved' => false]);

        $response = $this->actingAs($admin)->put(route('admin.speakers.update', $speaker->id), [
            'name' => $speaker->name,
            'photo_approved' => true,
        ]);

        $response->assertRedirect(route('admin.speakers.index'));
        $this->assertTrue($speaker->fresh()->photo_approved);
    }

    public function test_removing_a_photo_deletes_the_file(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $speaker = Speaker::factory()->create(['photo_path' => 'speakers/existing.jpg', 'photo_approved' => true]);
        Storage::disk('public')->put('speakers/existing.jpg', 'fake-bytes');

        $response = $this->actingAs($admin)->put(route('admin.speakers.update', $speaker->id), [
            'name' => $speaker->name,
            'remove_photo' => true,
        ]);

        $response->assertRedirect(route('admin.speakers.index'));
        Storage::disk('public')->assertMissing('speakers/existing.jpg');
        $speaker->refresh();
        $this->assertNull($speaker->photo_path);
        $this->assertFalse($speaker->photo_approved);
    }

    public function test_unapproved_speaker_photo_is_not_exposed_on_the_public_home_page(): void
    {
        $event = Event::factory()->create(['status' => 'published', 'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHours(2)]);
        $speaker = Speaker::factory()->create(['photo_path' => 'speakers/pending.jpg', 'photo_approved' => false]);
        $event->speakers()->attach($speaker);
        ProgrammeSession::factory()->create(['event_id' => $event->id])->speakers()->attach($speaker);

        $response = $this->get(route('home'));

        $response->assertInertia(fn ($page) => $page->where('event.speakers.0.photo_url', null));
    }
}
