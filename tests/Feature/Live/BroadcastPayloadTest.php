<?php

namespace Tests\Feature\Live;

use App\Enums\UserRole;
use App\Events\PollUpdated;
use App\Events\QuestionUpdated;
use App\Models\Event;
use App\Models\Poll;
use App\Models\Question;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BroadcastPayloadTest extends TestCase
{
    use RefreshDatabase;

    // T-RT-1 — a hidden/archived question's broadcastWith() has no body.
    public function test_hidden_question_broadcast_has_no_body(): void
    {
        $event = Event::factory()->create();
        $user = User::factory()->create();
        $question = Question::create([
            'event_id' => $event->id, 'user_id' => $user->id,
            'body' => 'Secret question body only staff should see right now.',
            'status' => 'hidden',
        ]);

        $payload = (new QuestionUpdated($question, 'hidden'))->broadcastWith();

        $this->assertArrayNotHasKey('body', $payload['question']);
        $this->assertSame(['id' => $question->id, 'status' => 'hidden'], $payload['question']);
    }

    public function test_approved_question_broadcast_includes_body(): void
    {
        $event = Event::factory()->create();
        $user = User::factory()->create();
        $question = Question::create([
            'event_id' => $event->id, 'user_id' => $user->id,
            'body' => 'A public, approved question.', 'status' => 'approved',
        ]);

        $payload = (new QuestionUpdated($question, 'approved'))->broadcastWith();

        $this->assertSame('A public, approved question.', $payload['question']['body']);
    }

    // T-RT-2 — vote counts absent unless show_results_live or closed.
    public function test_poll_broadcast_omits_votes_when_results_not_shown(): void
    {
        $event = Event::factory()->create();
        $poll = Poll::create(['event_id' => $event->id, 'question' => 'Q', 'status' => 'open', 'show_results_live' => false]);
        $option = $poll->options()->create(['label' => 'A', 'sort_order' => 0]);
        $option->responses()->create(['poll_id' => $poll->id, 'user_id' => User::factory()->create()->id]);

        $payload = (new PollUpdated($poll->fresh(), 'opened'))->broadcastWith();

        $this->assertArrayNotHasKey('votes', $payload['poll']['options'][0]);
    }

    public function test_poll_broadcast_includes_votes_when_results_live(): void
    {
        $event = Event::factory()->create();
        $poll = Poll::create(['event_id' => $event->id, 'question' => 'Q', 'status' => 'open', 'show_results_live' => true]);
        $option = $poll->options()->create(['label' => 'A', 'sort_order' => 0]);
        $option->responses()->create(['poll_id' => $poll->id, 'user_id' => User::factory()->create()->id]);

        $payload = (new PollUpdated($poll->fresh(), 'opened'))->broadcastWith();

        $this->assertSame(1, $payload['poll']['options'][0]['votes']);
    }

    public function test_poll_broadcast_includes_votes_once_closed_even_without_show_results_live(): void
    {
        $event = Event::factory()->create();
        $poll = Poll::create(['event_id' => $event->id, 'question' => 'Q', 'status' => 'closed', 'show_results_live' => false]);
        $poll->options()->create(['label' => 'A', 'sort_order' => 0]);

        $payload = (new PollUpdated($poll->fresh(), 'closed'))->broadcastWith();

        $this->assertArrayHasKey('votes', $payload['poll']['options'][0]);
    }

    // T-RT-2 — the /live page's initial props follow the same rule.
    public function test_live_page_omits_poll_votes_when_results_not_shown(): void
    {
        $event = Event::factory()->create(['status' => 'published']);
        $attendee = User::factory()->create(['role' => UserRole::Attendee]);
        $attendee->registrations()->create(['event_id' => $event->id, 'attendance_mode' => 'online', 'status' => 'registered']);

        $poll = Poll::create(['event_id' => $event->id, 'question' => 'Q', 'status' => 'open', 'show_results_live' => false]);
        $option = $poll->options()->create(['label' => 'A', 'sort_order' => 0]);
        $option->responses()->create(['poll_id' => $poll->id, 'user_id' => User::factory()->create()->id]);

        $response = $this->actingAs($attendee)->get(route('live.show'));

        $response->assertInertia(fn ($page) => $page
            ->component('Public/Live')
            ->where('poll.options.0.votes', null)
        );
    }
}
