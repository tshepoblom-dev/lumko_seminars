<?php

namespace Tests\Feature\Admin;

use App\Models\ProgrammeSession;
use App\Models\Speaker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesEventWorld;
use Tests\TestCase;

/**
 * Speakers are one shared library; events link speakers from it, and a
 * session names (possibly several) speakers from its own event's list.
 */
class EventSpeakerTest extends TestCase
{
    use CreatesEventWorld;
    use RefreshDatabase;

    public function test_speakers_from_the_library_can_be_linked_to_an_event_in_order(): void
    {
        $event = $this->makeEvent();
        [$a, $b] = Speaker::factory()->count(2)->create();

        $this->actingAs($this->makeSuperAdmin())
            ->post(route('admin.events.speakers.store', $event), ['speaker_ids' => [$b->id, $a->id]])
            ->assertSessionHas('success');

        $this->assertSame([$b->id, $a->id], $event->speakers()->pluck('speakers.id')->all());
    }

    public function test_one_speaker_can_be_linked_to_several_events(): void
    {
        $first = $this->makeEvent();
        $second = $this->makeEvent();
        $speaker = Speaker::factory()->create();
        $admin = $this->makeSuperAdmin();

        $this->actingAs($admin)->post(route('admin.events.speakers.store', $first), ['speaker_ids' => [$speaker->id]]);
        $this->actingAs($admin)->post(route('admin.events.speakers.store', $second), ['speaker_ids' => [$speaker->id]]);

        $this->assertEqualsCanonicalizing([$first->id, $second->id], $speaker->events()->pluck('events.id')->all());
        $this->assertSame(1, Speaker::count());
    }

    public function test_speakers_can_be_reordered(): void
    {
        $event = $this->makeEvent();
        [$a, $b, $c] = Speaker::factory()->count(3)->create();
        $event->speakers()->attach([$a->id => ['sort_order' => 0], $b->id => ['sort_order' => 1], $c->id => ['sort_order' => 2]]);

        $this->actingAs($this->makeSuperAdmin())
            ->post(route('admin.events.speakers.move', [$event, $c]), ['direction' => 'up']);

        $this->assertSame([$a->id, $c->id, $b->id], $event->speakers()->pluck('speakers.id')->all());
    }

    public function test_a_speaker_on_a_session_cannot_be_unlinked(): void
    {
        $event = $this->makeEvent();
        $speaker = Speaker::factory()->create();
        $event->speakers()->attach($speaker);
        ProgrammeSession::factory()->create(['event_id' => $event->id, 'title' => 'Keynote'])->speakers()->attach($speaker);

        $this->actingAs($this->makeSuperAdmin())
            ->delete(route('admin.events.speakers.destroy', [$event, $speaker]))
            ->assertSessionHas('error');

        $this->assertTrue($event->speakers()->whereKey($speaker->id)->exists());
    }

    public function test_unlinking_keeps_the_speaker_in_the_library(): void
    {
        $event = $this->makeEvent();
        $speaker = Speaker::factory()->create();
        $event->speakers()->attach($speaker);

        $this->actingAs($this->makeSuperAdmin())
            ->delete(route('admin.events.speakers.destroy', [$event, $speaker]))
            ->assertSessionHas('success');

        $this->assertFalse($event->speakers()->whereKey($speaker->id)->exists());
        $this->assertModelExists($speaker);
    }

    public function test_a_session_can_have_several_speakers_from_its_event(): void
    {
        $event = $this->makeEvent();
        [$a, $b] = Speaker::factory()->count(2)->create();
        $event->speakers()->attach([$a->id, $b->id]);

        $this->actingAs($this->makeSuperAdmin())
            ->post(route('admin.programme-sessions.store', $event), [
                'title' => 'Panel',
                'start_time' => '2026-11-13 08:55',
                'end_time' => '2026-11-13 09:07',
                'sort_order' => 4,
                'speaker_ids' => [$b->id, $a->id],
            ])
            ->assertRedirect(route('admin.programme-sessions.index', $event));

        $session = ProgrammeSession::where('title', 'Panel')->firstOrFail();
        $this->assertSame($event->id, $session->event_id);
        $this->assertSame([$b->id, $a->id], $session->speakers()->pluck('speakers.id')->all());
        $this->assertSame(
            "{$b->name} & {$a->name}",
            ProgrammeSession::speakerLine($session->load('speakers'))
        );
    }

    public function test_a_session_rejects_a_speaker_not_linked_to_its_event(): void
    {
        $event = $this->makeEvent();
        $outsider = Speaker::factory()->create();

        $this->actingAs($this->makeSuperAdmin())
            ->post(route('admin.programme-sessions.store', $event), [
                'title' => 'Talk',
                'start_time' => '2026-11-13 08:00',
                'end_time' => '2026-11-13 08:20',
                'sort_order' => 1,
                'speaker_ids' => [$outsider->id],
            ])
            ->assertSessionHasErrors('speaker_ids.0');

        $this->assertSame(0, ProgrammeSession::count());
    }

    public function test_session_speakers_can_be_changed(): void
    {
        $event = $this->makeEvent();
        [$a, $b] = Speaker::factory()->count(2)->create();
        $event->speakers()->attach([$a->id, $b->id]);
        $session = ProgrammeSession::factory()->create(['event_id' => $event->id]);
        $session->speakers()->attach($a);

        $this->actingAs($this->makeSuperAdmin())
            ->put(route('admin.programme-sessions.update', [$event, $session]), [
                'title' => $session->title,
                'start_time' => '2026-11-13 08:00',
                'end_time' => '2026-11-13 08:20',
                'sort_order' => 0,
                'speaker_ids' => [$b->id],
            ])
            ->assertRedirect(route('admin.programme-sessions.index', $event));

        $this->assertSame([$b->id], $session->speakers()->pluck('speakers.id')->all());
    }

    public function test_a_session_from_another_event_is_not_found_in_this_workspace(): void
    {
        $event = $this->makeEvent();
        $other = $this->makeEvent();
        $session = ProgrammeSession::factory()->create(['event_id' => $other->id]);

        $this->actingAs($this->makeSuperAdmin())
            ->get(route('admin.programme-sessions.edit', [$event, $session]))
            ->assertNotFound();
    }

    public function test_event_speakers_page_lists_linked_speakers_their_sessions_and_the_rest_of_the_library(): void
    {
        $event = $this->makeEvent();
        [$linked, $unlinked] = Speaker::factory()->count(2)->create();
        $event->speakers()->attach($linked);
        ProgrammeSession::factory()->create(['event_id' => $event->id, 'title' => 'Keynote'])->speakers()->attach($linked);

        $this->actingAs($this->makeSuperAdmin())
            ->get(route('admin.events.speakers.index', $event))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Events/Speakers')
                ->has('speakers', 1)
                ->where('speakers.0.id', $linked->id)
                ->where('speakers.0.sessions', ['Keynote'])
                ->has('library', 1)
                ->where('library.0.id', $unlinked->id)
            );
    }

    public function test_a_speaker_created_from_an_event_is_linked_to_it(): void
    {
        $event = $this->makeEvent();

        $this->actingAs($this->makeSuperAdmin())
            ->post(route('admin.speakers.store'), ['name' => 'Naledi Dube', 'for_event_id' => $event->id])
            ->assertRedirect(route('admin.events.speakers.index', $event));

        $this->assertSame(['Naledi Dube'], $event->speakers()->pluck('name')->all());
    }

    public function test_a_manager_cannot_link_speakers_to_an_event_they_do_not_manage(): void
    {
        $event = $this->makeEvent();
        $manager = $this->makeManagerFor($this->makeEvent());
        $speaker = Speaker::factory()->create();

        $this->actingAs($manager)
            ->post(route('admin.events.speakers.store', $event), ['speaker_ids' => [$speaker->id]])
            ->assertForbidden();

        $this->actingAs($manager)
            ->post(route('admin.speakers.store'), ['name' => 'Sneaky', 'for_event_id' => $event->id])
            ->assertRedirect(route('admin.speakers.index'));

        $this->assertSame(0, $event->speakers()->count());
    }

    public function test_public_home_page_lists_the_events_speakers_in_their_order(): void
    {
        $event = $this->makeEvent(['starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHours(2)]);
        $a = Speaker::factory()->create(['name' => 'Alpha']);
        $b = Speaker::factory()->create(['name' => 'Bravo']);
        // Bravo first; Alpha isn't on any session but is still an event speaker.
        $event->speakers()->attach([$b->id => ['sort_order' => 0], $a->id => ['sort_order' => 1]]);
        ProgrammeSession::factory()->create(['event_id' => $event->id])->speakers()->attach($b);

        $this->get(route('home'))->assertInertia(fn (Assert $page) => $page
            ->where('event.speakers.0.name', 'Bravo')
            ->where('event.speakers.1.name', 'Alpha')
        );
    }
}
