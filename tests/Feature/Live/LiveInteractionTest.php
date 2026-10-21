<?php

namespace Tests\Feature\Live;

use App\Enums\UserRole;
use App\Events\ChatMessageHidden;
use App\Events\ChatMessagePosted;
use App\Events\PollResultsUpdated;
use App\Events\PollUpdated;
use App\Events\QuestionSubmittedForModeration;
use App\Models\ChatMessage;
use App\Models\Event;
use App\Models\Poll;
use App\Models\Question;
use App\Models\Speaker;
use App\Models\User;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event as EventFacade;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The live page's real-time pieces: chat, poll results, and the presenter
 * tools on the Broadcast console and a speaker's stage link.
 */
class LiveInteractionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.agora.app_id' => str_repeat('a1', 16)]);
        config(['services.agora.app_certificate' => str_repeat('b2', 16)]);
    }

    private function agoraEvent(): Event
    {
        return Event::factory()->create([
            'status' => 'published',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHours(2),
            'livestream_provider' => 'agora',
            'livestream_channel_name' => 'power-of-data-main',
        ]);
    }

    private function attendee(Event $event, string $name = 'Thandiwe Mokoena'): User
    {
        $user = User::factory()->create(['role' => UserRole::Attendee, 'name' => $name, 'email_verified_at' => now()]);
        $user->registrations()->create(['event_id' => $event->id, 'attendance_mode' => 'online', 'status' => 'registered']);

        return $user;
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::SuperAdmin]);
    }

    private function poll(Event $event, array $attributes = []): Poll
    {
        $poll = Poll::create(['event_id' => $event->id, 'question' => 'Which topic next?', 'status' => 'open', ...$attributes]);
        $poll->options()->create(['label' => 'AI', 'sort_order' => 0]);
        $poll->options()->create(['label' => 'Data', 'sort_order' => 1]);

        return $poll->fresh('options');
    }

    /** A stage link for a speaker, as the Broadcast console creates it; returns the token. */
    private function stageToken(Event $event): string
    {
        $speaker = Speaker::factory()->create(['name' => 'Kgotla Paledi']);
        $event->speakers()->attach($speaker->id, ['sort_order' => 0]);

        $url = $this->actingAs($this->admin())
            ->postJson(route('admin.live.broadcast.stage-link.store', [$event, $speaker]))
            ->assertOk()
            ->json('url');

        // The speaker has no account.
        $this->app['auth']->forgetGuards();
        Inertia::flushShared();

        return basename(parse_url($url, PHP_URL_PATH));
    }

    // --- Chat ------------------------------------------------------------------

    public function test_a_chat_post_reaches_everyone_at_once_with_a_short_name(): void
    {
        EventFacade::fake([ChatMessagePosted::class]);
        $event = $this->agoraEvent();
        $attendee = $this->attendee($event);

        $this->actingAs($attendee)->post(route('live.chat.store', $event), ['body' => '  Hello from Durban  '])
            ->assertRedirect()->assertSessionHasNoErrors();

        $message = ChatMessage::sole();
        $this->assertSame('Hello from Durban', $message->body);
        EventFacade::assertDispatched(ChatMessagePosted::class, function (ChatMessagePosted $e) use ($event) {
            $payload = $e->broadcastWith()['message'];

            return $e->broadcastOn()[0]->name === "private-event.{$event->id}"
                && $payload['author'] === 'Thandiwe M.'
                && $payload['body'] === 'Hello from Durban';
        });
    }

    public function test_chat_needs_a_registration_and_a_short_message(): void
    {
        $event = $this->agoraEvent();
        $stranger = User::factory()->create(['role' => UserRole::Attendee, 'email_verified_at' => now()]);

        // The attendee gate answers 404 to someone registered for nothing, so
        // it never confirms the event exists.
        $this->actingAs($stranger)->post(route('live.chat.store', $event), ['body' => 'Hi'])->assertNotFound();

        $this->actingAs($this->attendee($event))->post(route('live.chat.store', $event), ['body' => str_repeat('a', 281)])
            ->assertSessionHasErrors('body');

        $this->assertSame(0, ChatMessage::count());
    }

    public function test_staff_hide_a_chat_post_and_it_leaves_the_live_page(): void
    {
        EventFacade::fake([ChatMessageHidden::class]);
        $event = $this->agoraEvent();
        $attendee = $this->attendee($event);
        $message = ChatMessage::create(['event_id' => $event->id, 'user_id' => $attendee->id, 'body' => 'Spam']);

        // Attendees can't.
        $this->actingAs($attendee)->post(route('admin.live.chat.hide', [$event, $message]));
        $this->assertNull($message->fresh()->hidden_at);

        $this->actingAs($this->admin())->post(route('admin.live.chat.hide', [$event, $message]))->assertRedirect();

        $this->assertNotNull($message->fresh()->hidden_at);
        EventFacade::assertDispatched(ChatMessageHidden::class, fn ($e) => $e->broadcastWith() === ['id' => $message->id]);

        $this->actingAs($attendee)->get(route('live.show'))
            ->assertInertia(fn (Assert $page) => $page->component('Public/Live')->has('chat', 0));
    }

    // --- Polls ---------------------------------------------------------------------

    public function test_every_vote_updates_presenters_but_attendees_only_when_results_are_live(): void
    {
        EventFacade::fake([PollResultsUpdated::class, PollUpdated::class]);
        $event = $this->agoraEvent();
        $hidden = $this->poll($event, ['show_results_live' => false]);

        $voter = $this->attendee($event);
        $this->actingAs($voter)->post(route('live.polls.respond', [$hidden, $hidden->options[0]]));
        // A second tap changes nothing and sends nothing.
        $this->actingAs($voter)->post(route('live.polls.respond', [$hidden, $hidden->options[1]]));

        EventFacade::assertDispatchedTimes(PollResultsUpdated::class, 1);
        EventFacade::assertNotDispatched(PollUpdated::class);
        EventFacade::assertDispatched(PollResultsUpdated::class, function (PollResultsUpdated $e) use ($event) {
            $poll = $e->broadcastWith()['poll'];

            return $e->broadcastOn()[0]->name === "private-presenters.event.{$event->id}"
                && $poll['total'] === 1 && $poll['options'][0]['votes'] === 1;
        });

        $hidden->update(['status' => 'closed']);
        $live = $this->poll($event, ['show_results_live' => true]);
        $this->actingAs($this->attendee($event, 'Sipho Dube'))->post(route('live.polls.respond', [$live, $live->options[1]]));

        EventFacade::assertDispatched(PollUpdated::class, fn (PollUpdated $e) => $e->action === 'votes' && $e->poll->is($live));
    }

    public function test_admin_can_create_and_launch_a_poll_from_the_console_as_json(): void
    {
        EventFacade::fake([PollUpdated::class, PollResultsUpdated::class]);
        $event = $this->agoraEvent();
        $admin = $this->admin();

        $created = $this->actingAs($admin)->postJson(route('admin.live.polls.store', $event), [
            'question' => 'Ready?', 'options' => ['Yes', 'No'], 'show_results_live' => true,
        ])->assertCreated()->json('polls.0');

        $this->assertSame('draft', $created['status']);
        $this->assertTrue($created['show_results_live']);

        $this->actingAs($admin)->postJson(route('admin.live.polls.open', [$event, $created['id']]))
            ->assertOk()->assertJsonPath('polls.0.status', 'open');
        EventFacade::assertDispatched(PollUpdated::class, fn ($e) => $e->action === 'opened');
    }

    // --- Speaker stage -------------------------------------------------------------

    public function test_a_speaker_creates_launches_and_closes_a_poll_from_their_stage_link(): void
    {
        EventFacade::fake([PollUpdated::class, PollResultsUpdated::class]);
        $event = $this->agoraEvent();
        $token = $this->stageToken($event);
        $earlier = $this->poll($event); // open: launching the new one closes it

        $pollId = collect($this->postJson(route('stage.polls.store', $token), [
            'question' => 'Did that land?', 'options' => ['Yes', 'Not quite'],
        ])->assertCreated()->json('polls'))->firstWhere('question', 'Did that land?')['id'];

        $this->assertNotNull(Poll::find($pollId)->created_by_speaker_id);

        $this->postJson(route('stage.polls.open', [$token, $pollId]))->assertOk();
        $this->assertSame('open', Poll::find($pollId)->status->value);
        $this->assertSame('closed', $earlier->fresh()->status->value);

        $this->postJson(route('stage.polls.close', [$token, $pollId]))->assertOk();
        $this->assertSame('closed', Poll::find($pollId)->status->value);
    }

    public function test_a_stage_link_only_reaches_its_own_event_and_approved_questions(): void
    {
        $event = $this->agoraEvent();
        $token = $this->stageToken($event);
        $asker = $this->attendee($event);

        Question::create(['event_id' => $event->id, 'user_id' => $asker->id, 'body' => 'Approved one', 'status' => 'approved']);
        Question::create(['event_id' => $event->id, 'user_id' => $asker->id, 'body' => 'Still pending', 'status' => 'submitted']);

        $state = $this->getJson(route('stage.live', $token))->assertOk()->json();
        $this->assertSame(['Approved one'], array_column($state['questions'], 'body'));

        $otherEventPoll = $this->poll($this->agoraEvent());
        $this->postJson(route('stage.polls.open', [$token, $otherEventPoll]))->assertNotFound();

        $this->getJson(route('stage.live', 'not-a-real-token'))->assertNotFound();
    }

    public function test_a_stage_link_signs_only_its_events_audience_and_presenter_channels(): void
    {
        config([
            'broadcasting.default' => 'pusher',
            'broadcasting.connections.pusher.key' => 'test-key',
            'broadcasting.connections.pusher.secret' => 'test-secret',
            'broadcasting.connections.pusher.app_id' => '1',
        ]);
        Broadcast::purge('pusher');

        $event = $this->agoraEvent();
        $token = $this->stageToken($event);

        foreach (["private-event.{$event->id}", "private-presenters.event.{$event->id}"] as $channel) {
            $auth = $this->postJson(route('stage.broadcasting-auth', $token), ['socket_id' => '123.456', 'channel_name' => $channel])
                ->assertOk()->json('auth');
            $this->assertStringStartsWith('test-key:', $auth);
        }

        foreach (["private-admin.event.{$event->id}", 'private-event.999999'] as $channel) {
            $this->postJson(route('stage.broadcasting-auth', $token), ['socket_id' => '123.456', 'channel_name' => $channel])
                ->assertForbidden();
        }
    }

    // --- Channels and events ---------------------------------------------------------

    public function test_only_the_events_staff_join_the_presenters_channel(): void
    {
        $event = $this->agoraEvent();
        $callback = Broadcast::getChannels()['presenters.event.{eventId}'];

        $this->assertTrue((bool) $callback($this->admin(), $event->id));
        $this->assertFalse((bool) $callback($this->attendee($event), $event->id));
    }

    public function test_new_questions_reach_moderators_without_a_queue_worker(): void
    {
        $this->assertContains(ShouldBroadcastNow::class, class_implements(QuestionSubmittedForModeration::class));
    }
}
