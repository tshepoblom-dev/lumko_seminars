<?php

namespace Tests\Feature\Live;

use App\Enums\UserRole;
use App\Events\ReactionAggregateUpdated;
use App\Models\Event;
use App\Models\Reaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event as EventFacade;
use Tests\TestCase;

class ReactionTest extends TestCase
{
    use RefreshDatabase;

    private function registeredAttendee(Event $event): User
    {
        $attendee = User::factory()->create(['role' => UserRole::Attendee]);
        $attendee->registrations()->create(['event_id' => $event->id, 'attendance_mode' => 'online', 'status' => 'registered']);

        return $attendee;
    }

    // T-RT-5 — missing type is rejected; a valid type is accepted and aggregated.
    public function test_reaction_without_type_is_rejected(): void
    {
        $event = Event::factory()->create(['status' => 'published']);
        $attendee = $this->registeredAttendee($event);

        $response = $this->actingAs($attendee)->post(route('live.reactions.store', $event->id), []);

        $response->assertSessionHasErrors('type');
        $this->assertSame(0, Reaction::count());
    }

    public function test_reaction_is_recorded_and_broadcast_is_aggregated_not_per_click(): void
    {
        EventFacade::fake(ReactionAggregateUpdated::class);

        $event = Event::factory()->create(['status' => 'published']);
        $attendee = $this->registeredAttendee($event);
        $another = $this->registeredAttendee($event);

        $this->actingAs($attendee)->post(route('live.reactions.store', $event->id), ['type' => 'heart']);
        $this->actingAs($another)->post(route('live.reactions.store', $event->id), ['type' => 'heart']);

        $this->assertSame(2, Reaction::where('type', 'heart')->count());

        // Both clicks land inside the same 2s window, so only the first
        // one's broadcast fires (the cache lock blocks the second).
        EventFacade::assertDispatchedTimes(ReactionAggregateUpdated::class, 1);
        EventFacade::assertDispatched(function (ReactionAggregateUpdated $e) use ($event) {
            return $e->eventId === $event->id && ($e->counts['heart'] ?? 0) === 1;
        });
    }

    // The old text reactions still read from existing rows, but can't be sent.
    public function test_only_the_emoji_reactions_are_accepted(): void
    {
        $event = Event::factory()->create(['status' => 'published']);
        $attendee = $this->registeredAttendee($event);

        foreach (['thumbs', 'heart', 'clap', 'party', 'laugh', 'think'] as $type) {
            $this->actingAs($attendee)->post(route('live.reactions.store', $event->id), ['type' => $type])
                ->assertSessionHasNoErrors();
        }

        $this->actingAs($attendee)->post(route('live.reactions.store', $event->id), ['type' => 'useful'])
            ->assertSessionHasErrors('type');

        $this->assertSame(6, Reaction::count());
    }
}
