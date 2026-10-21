<?php

namespace Tests\Feature\Live;

use App\Enums\UserRole;
use App\Events\QuestionSubmittedForModeration;
use App\Models\Event;
use App\Models\Question;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event as EventFacade;
use Tests\TestCase;

class QuestionModerationTest extends TestCase
{
    use RefreshDatabase;

    private function registeredAttendee(Event $event): User
    {
        $attendee = User::factory()->create(['role' => UserRole::Attendee]);
        $attendee->registrations()->create(['event_id' => $event->id, 'attendance_mode' => 'online', 'status' => 'registered']);

        return $attendee;
    }

    public function test_attendee_can_submit_a_question(): void
    {
        // Scoped to this one broadcast event only — faking *all* events
        // (bare Event::fake()) also disables Eloquent model events, which
        // would silently break EventRegistration's public_id-on-create hook.
        EventFacade::fake(QuestionSubmittedForModeration::class);

        $event = Event::factory()->create(['status' => 'published']);
        $attendee = $this->registeredAttendee($event);

        $response = $this->actingAs($attendee)->post(route('live.questions.store', $event->id), [
            'body' => 'How does this apply to municipalities?',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('questions', ['event_id' => $event->id, 'status' => 'submitted']);
    }

    // C6 — same user, same normalised text, within 5 minutes: refused.
    public function test_duplicate_question_within_five_minutes_is_refused(): void
    {
        EventFacade::fake(QuestionSubmittedForModeration::class);

        $event = Event::factory()->create(['status' => 'published']);
        $attendee = $this->registeredAttendee($event);

        $this->actingAs($attendee)->post(route('live.questions.store', $event->id), [
            'body' => 'How does this apply to municipalities?',
        ]);

        // Different casing/whitespace still counts as the same question.
        $response = $this->actingAs($attendee)->post(route('live.questions.store', $event->id), [
            'body' => '  How Does this apply  to municipalities?  ',
        ]);

        $response->assertSessionHasErrors('body');
        $this->assertSame(1, Question::where('event_id', $event->id)->count());
    }

    public function test_different_question_from_the_same_user_is_not_treated_as_duplicate(): void
    {
        EventFacade::fake(QuestionSubmittedForModeration::class);

        $event = Event::factory()->create(['status' => 'published']);
        $attendee = $this->registeredAttendee($event);

        $this->actingAs($attendee)->post(route('live.questions.store', $event->id), ['body' => 'First question here.']);
        $response = $this->actingAs($attendee)->post(route('live.questions.store', $event->id), ['body' => 'A completely different question.']);

        $response->assertSessionDoesntHaveErrors('body');
        $this->assertSame(2, Question::where('event_id', $event->id)->count());
    }

    public function test_unregistered_user_cannot_submit_a_question(): void
    {
        $event = Event::factory()->create(['status' => 'published']);
        $notRegistered = User::factory()->create(['role' => UserRole::Attendee]);

        $response = $this->actingAs($notRegistered)->post(route('live.questions.store', $event->id), [
            'body' => 'Trying to sneak a question in.',
        ]);

        $response->assertNotFound();
        $this->assertDatabaseMissing('questions', ['event_id' => $event->id]);
    }

    public function test_unapproved_question_is_not_visible_via_upvote(): void
    {
        $event = Event::factory()->create(['status' => 'published']);
        $attendee = $this->registeredAttendee($event);
        $question = Question::create([
            'event_id' => $event->id, 'user_id' => $attendee->id, 'body' => 'Q', 'status' => 'submitted',
        ]);

        $response = $this->actingAs($attendee)->post(route('live.questions.upvote', $question->id));

        $response->assertForbidden();
    }

    public function test_admin_can_approve_and_feature_a_question(): void
    {
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $event = Event::factory()->create(['status' => 'published']);
        $attendee = $this->registeredAttendee($event);
        $question = Question::create([
            'event_id' => $event->id, 'user_id' => $attendee->id, 'body' => 'Q', 'status' => 'submitted',
        ]);

        $this->actingAs($admin)->post(route('admin.live.questions.approve', [$event, $question]));
        $this->assertSame('approved', $question->fresh()->status->value);

        $this->actingAs($admin)->post(route('admin.live.questions.feature', [$event, $question]));
        $this->assertTrue($question->fresh()->is_featured);
    }

    public function test_approved_question_can_be_upvoted_once_per_user(): void
    {
        $event = Event::factory()->create(['status' => 'published']);
        $attendee = $this->registeredAttendee($event);
        $question = Question::create([
            'event_id' => $event->id, 'user_id' => $attendee->id, 'body' => 'Q', 'status' => 'approved',
        ]);

        $this->actingAs($attendee)->post(route('live.questions.upvote', $question->id));
        $this->actingAs($attendee)->post(route('live.questions.upvote', $question->id));

        $this->assertSame(1, $question->votes()->count());
    }
}
