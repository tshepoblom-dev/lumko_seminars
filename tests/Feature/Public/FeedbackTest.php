<?php

namespace Tests\Feature\Public;

use App\Enums\UserRole;
use App\Models\Event;
use App\Models\Feedback;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeedbackTest extends TestCase
{
    use RefreshDatabase;

    private function registeredAttendee(Event $event): User
    {
        $user = User::factory()->create(['role' => UserRole::Attendee]);
        $user->registrations()->create(['event_id' => $event->id, 'attendance_mode' => 'online', 'status' => 'registered']);

        return $user;
    }

    public function test_attendee_can_submit_feedback(): void
    {
        $event = Event::factory()->create(['status' => 'published', 'ends_at' => now()->subDay()]);
        $attendee = $this->registeredAttendee($event);

        $response = $this->actingAs($attendee)->post(route('feedback.store'), [
            'rating' => 5,
            'biggest_takeaway' => 'Data governance matters more than I thought.',
            'topics_next' => ['AI', 'Data governance'],
            'wants_contact' => true,
            'contact_topics' => ['AI'],
        ]);

        $response->assertRedirect(route('feedback.thanks'));
        $this->assertDatabaseHas('feedback', [
            'event_id' => $event->id, 'user_id' => $attendee->id, 'rating' => 5, 'wants_contact' => true,
        ]);
    }

    // T-FB-1 — a client-supplied event_id is ignored; the event always
    // comes from the caller's own registration.
    public function test_client_supplied_event_id_is_ignored(): void
    {
        $event = Event::factory()->create(['status' => 'published', 'ends_at' => now()->subDay()]);
        $otherEvent = Event::factory()->create(['status' => 'published', 'ends_at' => now()->subDay()]);
        $attendee = $this->registeredAttendee($event);

        $this->actingAs($attendee)->post(route('feedback.store'), [
            'event_id' => $otherEvent->id,
            'rating' => 4,
        ]);

        $this->assertDatabaseHas('feedback', ['event_id' => $event->id, 'user_id' => $attendee->id]);
        $this->assertDatabaseMissing('feedback', ['event_id' => $otherEvent->id, 'user_id' => $attendee->id]);
    }

    // T-FB-2 — wants_contact writes a follow_up_request ledger entry.
    public function test_wants_contact_records_follow_up_request_consent(): void
    {
        $event = Event::factory()->create(['status' => 'published', 'ends_at' => now()->subDay()]);
        $attendee = $this->registeredAttendee($event);

        $this->actingAs($attendee)->post(route('feedback.store'), [
            'rating' => 5,
            'wants_contact' => true,
            'contact_topics' => ['AI'],
        ]);

        // FollowUpRequest is ledger-only by design (B10) — it has no
        // AttendeeConsent projection row, so the ledger itself is the
        // assertion, not ConsentService::isGranted().
        $this->assertDatabaseHas('consent_records', [
            'user_id' => $attendee->id, 'type' => 'follow_up_request', 'action' => 'granted', 'source' => 'feedback',
        ]);
    }

    public function test_feedback_is_not_open_before_the_event_ends(): void
    {
        $event = Event::factory()->create(['status' => 'published', 'ends_at' => now()->addWeek()]);
        $attendee = $this->registeredAttendee($event);

        $response = $this->actingAs($attendee)->post(route('feedback.store'), ['rating' => 5]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('feedback', ['event_id' => $event->id]);
    }

    public function test_feedback_closes_after_the_configured_window(): void
    {
        $event = Event::factory()->create(['status' => 'published', 'ends_at' => now()->subDays(45)]);
        $attendee = $this->registeredAttendee($event);

        $response = $this->actingAs($attendee)->post(route('feedback.store'), ['rating' => 5]);

        $response->assertStatus(422);
    }

    public function test_contact_topics_required_when_wants_contact_is_true(): void
    {
        $event = Event::factory()->create(['status' => 'published', 'ends_at' => now()->subDay()]);
        $attendee = $this->registeredAttendee($event);

        $response = $this->actingAs($attendee)->post(route('feedback.store'), [
            'rating' => 4,
            'wants_contact' => true,
        ]);

        $response->assertSessionHasErrors('contact_topics');
    }

    public function test_invalid_topic_option_is_rejected(): void
    {
        $event = Event::factory()->create(['status' => 'published', 'ends_at' => now()->subDay()]);
        $attendee = $this->registeredAttendee($event);

        $response = $this->actingAs($attendee)->post(route('feedback.store'), [
            'rating' => 4,
            'topics_next' => ['Not a real topic'],
        ]);

        $response->assertSessionHasErrors('topics_next.0');
    }

    public function test_resubmitting_feedback_updates_rather_than_duplicates(): void
    {
        $event = Event::factory()->create(['status' => 'published', 'ends_at' => now()->subDay()]);
        $attendee = $this->registeredAttendee($event);

        $this->actingAs($attendee)->post(route('feedback.store'), ['rating' => 2]);
        $this->actingAs($attendee)->post(route('feedback.store'), ['rating' => 5]);

        $this->assertSame(1, Feedback::where('event_id', $event->id)->where('user_id', $attendee->id)->count());
        $this->assertSame(5, Feedback::first()->rating);
    }
}
