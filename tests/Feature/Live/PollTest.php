<?php

namespace Tests\Feature\Live;

use App\Enums\UserRole;
use App\Models\Event;
use App\Models\Poll;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PollTest extends TestCase
{
    use RefreshDatabase;

    private function registeredAttendee(Event $event): User
    {
        $attendee = User::factory()->create(['role' => UserRole::Attendee]);
        $attendee->registrations()->create(['event_id' => $event->id, 'attendance_mode' => 'online', 'status' => 'registered']);

        return $attendee;
    }

    public function test_admin_can_create_open_and_close_a_poll(): void
    {
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $event = Event::factory()->create(['status' => 'published']);

        $this->actingAs($admin)->post(route('admin.live.polls.store', $event), [
            'question' => 'How mature is your data usage?',
            'options' => ['Spreadsheets', 'Dashboards', 'Advanced analytics'],
        ]);

        $poll = Poll::first();
        $this->assertSame('draft', $poll->status->value);
        $this->assertCount(3, $poll->options);

        $this->actingAs($admin)->post(route('admin.live.polls.open', [$event, $poll]));
        $this->assertSame('open', $poll->fresh()->status->value);

        $this->actingAs($admin)->post(route('admin.live.polls.close', [$event, $poll]));
        $this->assertSame('closed', $poll->fresh()->status->value);
    }

    public function test_attendee_can_respond_to_open_poll_once(): void
    {
        $event = Event::factory()->create(['status' => 'published']);
        $poll = Poll::create(['event_id' => $event->id, 'question' => 'Q', 'status' => 'open']);
        $option = $poll->options()->create(['label' => 'A', 'sort_order' => 0]);
        $attendee = $this->registeredAttendee($event);

        $this->actingAs($attendee)->post(route('live.polls.respond', [$poll->id, $option->id]));
        $this->actingAs($attendee)->post(route('live.polls.respond', [$poll->id, $option->id]));

        $this->assertSame(1, $poll->responses()->count());
    }

    public function test_cannot_respond_to_closed_poll(): void
    {
        $event = Event::factory()->create(['status' => 'published']);
        $poll = Poll::create(['event_id' => $event->id, 'question' => 'Q', 'status' => 'closed']);
        $option = $poll->options()->create(['label' => 'A', 'sort_order' => 0]);
        $attendee = $this->registeredAttendee($event);

        $response = $this->actingAs($attendee)->post(route('live.polls.respond', [$poll->id, $option->id]));

        $response->assertForbidden();
    }

    // T-RT-4 — opening a poll closes any other open poll for the event.
    public function test_opening_a_poll_closes_any_other_open_poll(): void
    {
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $event = Event::factory()->create(['status' => 'published']);
        $pollA = Poll::create(['event_id' => $event->id, 'question' => 'A', 'status' => 'open']);
        $pollB = Poll::create(['event_id' => $event->id, 'question' => 'B', 'status' => 'draft']);

        $this->actingAs($admin)->post(route('admin.live.polls.open', [$event, $pollB]));

        $this->assertSame('open', $pollB->fresh()->status->value);
        $this->assertSame('closed', $pollA->fresh()->status->value);
    }

    // T-ACL-1 — an unregistered user cannot respond at all.
    public function test_unregistered_user_cannot_respond_to_poll(): void
    {
        $event = Event::factory()->create(['status' => 'published']);
        $poll = Poll::create(['event_id' => $event->id, 'question' => 'Q', 'status' => 'open']);
        $option = $poll->options()->create(['label' => 'A', 'sort_order' => 0]);
        $notRegistered = User::factory()->create(['role' => UserRole::Attendee]);

        $response = $this->actingAs($notRegistered)->post(route('live.polls.respond', [$poll->id, $option->id]));

        $response->assertNotFound();
        $this->assertSame(0, $poll->responses()->count());
    }
}
