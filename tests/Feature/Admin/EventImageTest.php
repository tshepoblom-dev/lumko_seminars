<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Each event carries its own hero and venue photography, uploaded from the
 * admin event form, instead of one set of files hardcoded in the frontend.
 */
class EventImageTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::SuperAdmin]);
    }

    private function eventFields(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Imaged Event',
            'subtitle' => 'A subtitle',
            'slug' => 'imaged-event',
            'description' => 'Description',
            'venue_name' => 'Venue',
            'venue_address' => 'Address',
            'starts_at' => now()->addWeek()->format('Y-m-d H:i:s'),
            'ends_at' => now()->addWeek()->addHour()->format('Y-m-d H:i:s'),
            'online_price' => 0,
            'in_person_price' => 150,
            'status' => 'draft',
        ], $overrides);
    }

    private function updateFields(Event $event, array $overrides = []): array
    {
        return $this->eventFields(array_merge([
            'name' => $event->name,
            'slug' => $event->slug,
            'starts_at' => $event->starts_at->format('Y-m-d H:i:s'),
            'ends_at' => $event->ends_at->format('Y-m-d H:i:s'),
            'status' => $event->status,
        ], $overrides));
    }

    public function test_images_uploaded_on_create_are_stored_with_their_alt_text(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin())->post(route('admin.events.store'), $this->eventFields([
            'hero_image' => UploadedFile::fake()->image('hero.jpg', 2400, 1600),
            'hero_image_alt' => 'The lodge at dusk',
            'venue_image' => UploadedFile::fake()->image('venue.jpg', 2400, 1200),
            'venue_image_alt' => 'The conference room',
        ]))->assertRedirect(route('admin.events.show', Event::latest('id')->first()));

        $event = Event::where('slug', 'imaged-event')->firstOrFail();

        $this->assertStringStartsWith('events/', $event->hero_image_path);
        $this->assertSame('The lodge at dusk', $event->hero_image_alt);
        $this->assertSame('The conference room', $event->venue_image_alt);
        Storage::disk('public')->assertExists($event->hero_image_path);
        Storage::disk('public')->assertExists($event->venue_image_path);
    }

    public function test_an_image_needs_alt_text(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin())->post(route('admin.events.store'), $this->eventFields([
            'hero_image' => UploadedFile::fake()->image('hero.jpg'),
        ]))->assertSessionHasErrors('hero_image_alt');

        $this->assertDatabaseMissing('events', ['slug' => 'imaged-event']);
    }

    public function test_a_non_image_file_is_rejected(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin())->post(route('admin.events.store'), $this->eventFields([
            'venue_image' => UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf'),
            'venue_image_alt' => 'Not a photo',
        ]))->assertSessionHasErrors('venue_image');
    }

    public function test_replacing_an_image_deletes_the_old_file(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('events/old-hero.jpg', 'old');
        $event = Event::factory()->create(['hero_image_path' => 'events/old-hero.jpg', 'hero_image_alt' => 'Old']);

        $this->actingAs($this->admin())->put(route('admin.events.update', $event), $this->updateFields($event, [
            'hero_image' => UploadedFile::fake()->image('new.jpg'),
            'hero_image_alt' => 'New view',
        ]))->assertRedirect(route('admin.events.show', $event));

        $event->refresh();
        Storage::disk('public')->assertMissing('events/old-hero.jpg');
        Storage::disk('public')->assertExists($event->hero_image_path);
        $this->assertSame('New view', $event->hero_image_alt);
    }

    public function test_an_image_can_be_removed(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('events/venue.jpg', 'venue');
        $event = Event::factory()->create(['venue_image_path' => 'events/venue.jpg', 'venue_image_alt' => 'Room']);

        $this->actingAs($this->admin())->put(route('admin.events.update', $event), $this->updateFields($event, [
            'remove_venue_image' => true,
        ]))->assertRedirect(route('admin.events.show', $event));

        $event->refresh();
        $this->assertNull($event->venue_image_path);
        $this->assertNull($event->venue_image_alt);
        Storage::disk('public')->assertMissing('events/venue.jpg');
    }

    public function test_an_update_without_a_file_keeps_the_existing_image(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('events/hero.jpg', 'hero');
        $event = Event::factory()->create(['hero_image_path' => 'events/hero.jpg', 'hero_image_alt' => 'Lodge']);

        $this->actingAs($this->admin())->put(route('admin.events.update', $event), $this->updateFields($event, [
            'name' => 'Renamed',
        ]))->assertRedirect(route('admin.events.show', $event));

        $event->refresh();
        $this->assertSame('events/hero.jpg', $event->hero_image_path);
        $this->assertSame('Lodge', $event->hero_image_alt);
        Storage::disk('public')->assertExists('events/hero.jpg');
    }

    public function test_the_home_page_receives_the_event_images(): void
    {
        Event::factory()->create([
            'status' => 'published',
            'starts_at' => now()->addMonth(),
            'ends_at' => now()->addMonth()->addHour(),
            'hero_image_path' => 'events/hero.jpg',
            'hero_image_alt' => 'The lodge at dusk',
        ]);

        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->component('Public/Home')
            ->where('event.hero_image_url', asset('storage/events/hero.jpg'))
            ->where('event.hero_image_alt', 'The lodge at dusk')
            ->where('event.venue_image_url', null)
        );
    }
}
