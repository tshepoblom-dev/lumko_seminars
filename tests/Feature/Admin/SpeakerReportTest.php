<?php

namespace Tests\Feature\Admin;

use App\Models\ChatMessage;
use App\Models\Event;
use App\Models\Poll;
use App\Models\PollOption;
use App\Models\PollResponse;
use App\Models\ProgrammeSession;
use App\Models\Question;
use App\Models\Reaction;
use App\Models\Speaker;
use App\Models\SpeakerConnection;
use App\Notifications\SpeakerReportLinkNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesEventWorld;
use Tests\TestCase;

class SpeakerReportTest extends TestCase
{
    use CreatesEventWorld, RefreshDatabase;

    private function speakerOn(Event $event, array $attributes = []): Speaker
    {
        $speaker = Speaker::factory()->create(['name' => 'Naledi Dube', 'contact_email' => 'naledi@example.com', ...$attributes]);
        $event->speakers()->attach($speaker->id, ['sort_order' => 0]);

        return $speaker;
    }

    private function connect(Event $event, Speaker $speaker, array $userAttributes = [], ?string $note = null)
    {
        $attendee = $this->registerAttendee($event, 'online', $userAttributes)->user;
        $this->actingAs($attendee)->post(route('speakers.connect', [$event, $speaker]), ['note' => $note]);

        return $attendee;
    }

    /** Sends the link as an admin and returns the URL from the email. */
    private function sendLink(Event $event, Speaker $speaker): string
    {
        Notification::fake();
        $this->actingAs($this->makeSuperAdmin())
            ->post(route('admin.events.speakers.report.send', [$event, $speaker]))
            ->assertSessionHas('success');

        $url = null;
        Notification::assertSentOnDemand(SpeakerReportLinkNotification::class, function ($n, $channels, AnonymousNotifiable $notifiable) use (&$url) {
            $url = $n->url;

            return $notifiable->routes['mail'] === 'naledi@example.com';
        });

        // The speaker opens the link logged out. Inertia's shared props are
        // a singleton across requests in one test (not in production), so
        // drop the admin workspace prop that request registered.
        auth()->logout();
        Inertia::flushShared();

        return $url;
    }

    public function test_a_speaker_without_a_contact_email_cannot_be_sent_a_link(): void
    {
        Notification::fake();
        $event = $this->makeEvent();
        $speaker = $this->speakerOn($event, ['contact_email' => null]);

        $this->actingAs($this->makeSuperAdmin())
            ->post(route('admin.events.speakers.report.send', [$event, $speaker]))
            ->assertSessionHas('error');

        Notification::assertNothingSent();
        $link = DB::table('event_speaker')->where('speaker_id', $speaker->id)->first();
        $this->assertNull($link->report_token_hash);
        $this->assertNull($link->report_sent_at);
    }

    public function test_the_emailed_link_shows_only_people_who_connected_and_is_audited(): void
    {
        $event = $this->makeEvent();
        $speaker = $this->speakerOn($event);
        $this->connect($event, $speaker, ['name' => 'Thabo Connected', 'email' => 'thabo@example.com'], 'Let us talk');
        $this->registerAttendee($event, 'online', ['name' => 'Not Connected']);

        $url = $this->sendLink($event, $speaker);

        $response = $this->get($url);
        $response->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Public/SpeakerReport')
            ->has('contacts', 1)
            ->where('contacts.0.name', 'Thabo Connected')
            ->where('contacts.0.email', 'thabo@example.com')
            ->where('contacts.0.note', 'Let us talk')
            ->where('preview', false));
        $this->assertStringNotContainsString('Not Connected', $response->getContent());

        $this->assertDatabaseHas('audit_logs', ['action' => 'speaker.report_link_sent', 'subject_id' => $speaker->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'speaker.report_viewed', 'subject_id' => $speaker->id]);
    }

    public function test_a_withdrawal_disappears_from_the_page_and_the_csv_which_is_formula_safe(): void
    {
        $event = $this->makeEvent();
        $speaker = $this->speakerOn($event);
        $this->connect($event, $speaker, ['name' => 'Stays In'], '=HYPERLINK("http://evil")');
        $leaver = $this->connect($event, $speaker, ['name' => 'Walks Away']);

        $url = $this->sendLink($event, $speaker);

        $connection = SpeakerConnection::where('user_id', $leaver->id)->sole();
        $this->actingAs($leaver)->delete(route('attendee.speaker-connections.destroy', $connection));
        auth()->logout();

        $this->get($url)->assertInertia(fn (Assert $page) => $page->has('contacts', 1)->where('contacts.0.name', 'Stays In'));

        $csv = $this->get($url.'/contacts.csv');
        $csv->assertOk();
        $body = $csv->streamedContent();
        $this->assertStringContainsString('Stays In', $body);
        $this->assertStringNotContainsString('Walks Away', $body);
        $this->assertStringContainsString("'=HYPERLINK", $body);
        $this->assertDatabaseHas('audit_logs', ['action' => 'speaker.contacts_downloaded']);
    }

    public function test_expired_revoked_and_replaced_links_stop_working(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $event = $this->makeEvent();
        $speaker = $this->speakerOn($event);

        $first = $this->sendLink($event, $speaker);
        $this->get($first)->assertOk();

        $second = $this->sendLink($event, $speaker);
        $this->get($first)->assertNotFound();
        $this->get($second)->assertOk();

        $this->actingAs($this->makeSuperAdmin())->post(route('admin.events.speakers.report.revoke', [$event, $speaker]));
        auth()->logout();
        Inertia::flushShared();
        $this->get($second)->assertNotFound();

        config(['seminar.speaker_report_days' => 30]);
        $third = $this->sendLink($event, $speaker);
        $this->travel(31)->days();
        $this->get($third)->assertNotFound();

        $this->get('/speaker-report/not-a-real-token')->assertNotFound();
    }

    public function test_a_manager_of_another_event_cannot_send_or_preview(): void
    {
        Notification::fake();
        $event = $this->makeEvent();
        $speaker = $this->speakerOn($event);
        $outsider = $this->makeManagerFor($this->makeEvent());

        $this->actingAs($outsider)->post(route('admin.events.speakers.report.send', [$event, $speaker]))->assertForbidden();
        $this->actingAs($outsider)->get(route('admin.events.speakers.report.preview', [$event, $speaker]))->assertForbidden();
        Notification::assertNothingSent();

        $this->actingAs($this->makeManagerFor($event))
            ->get(route('admin.events.speakers.report.preview', [$event, $speaker]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('preview', true)->where('csvUrl', null));
        $this->assertDatabaseHas('audit_logs', ['action' => 'speaker.report_previewed']);
    }

    public function test_the_session_report_covers_only_this_speakers_sessions_and_polls(): void
    {
        $event = $this->makeEvent();
        $speaker = $this->speakerOn($event);
        $otherSpeaker = $this->speakerOn($event, ['name' => 'Other Speaker', 'contact_email' => 'o@example.com']);
        $mine = ProgrammeSession::factory()->create(['event_id' => $event->id, 'title' => 'Data in practice']);
        $theirs = ProgrammeSession::factory()->create(['event_id' => $event->id, 'title' => 'Someone else']);
        $mine->speakers()->attach($speaker->id, ['sort_order' => 0]);
        $theirs->speakers()->attach($otherSpeaker->id, ['sort_order' => 0]);
        $asker = $this->registerAttendee($event)->user;

        Question::factory()->approved()->create(['event_id' => $event->id, 'user_id' => $asker->id, 'programme_session_id' => $mine->id, 'body' => 'How do we start?']);
        Question::factory()->create(['event_id' => $event->id, 'user_id' => $asker->id, 'programme_session_id' => $mine->id, 'body' => 'Unapproved question?']);
        Question::factory()->approved()->create(['event_id' => $event->id, 'user_id' => $asker->id, 'programme_session_id' => $theirs->id, 'body' => 'Not for you?']);
        ChatMessage::create(['event_id' => $event->id, 'programme_session_id' => $mine->id, 'user_id' => $asker->id, 'body' => 'Great talk']);
        Reaction::create(['event_id' => $event->id, 'programme_session_id' => $mine->id, 'user_id' => $asker->id, 'type' => 'clap']);
        Reaction::create(['event_id' => $event->id, 'programme_session_id' => $mine->id, 'user_id' => $asker->id, 'type' => 'clap']);

        $myPoll = Poll::factory()->create(['event_id' => $event->id, 'created_by_speaker_id' => $speaker->id, 'question' => 'Who has a data strategy?', 'status' => 'closed']);
        $yes = PollOption::create(['poll_id' => $myPoll->id, 'label' => 'Yes', 'sort_order' => 0]);
        PollOption::create(['poll_id' => $myPoll->id, 'label' => 'No', 'sort_order' => 1]);
        PollResponse::create(['poll_id' => $myPoll->id, 'poll_option_id' => $yes->id, 'user_id' => $asker->id]);
        Poll::factory()->create(['event_id' => $event->id, 'created_by_speaker_id' => $otherSpeaker->id, 'question' => 'Their poll', 'status' => 'closed']);

        $this->actingAs($this->makeSuperAdmin())
            ->get(route('admin.events.speakers.report.preview', [$event, $speaker]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('sessions', 1)
                ->where('sessions.0.title', 'Data in practice')
                ->where('sessions.0.questions_submitted', 2)
                ->has('sessions.0.questions', 1)
                ->where('sessions.0.questions.0.body', 'How do we start?')
                ->where('sessions.0.chat_messages', 1)
                ->where('sessions.0.reactions.clap', 2)
                ->has('polls', 1)
                ->where('polls.0.question', 'Who has a data strategy?')
                ->where('polls.0.responses', 1)
                ->where('polls.0.options.0.responses', 1));
    }

    public function test_small_industry_groups_are_folded_into_other(): void
    {
        $event = $this->makeEvent();
        $speaker = $this->speakerOn($event);
        foreach (range(1, 5) as $i) {
            $this->registerAttendee($event)->update(['industry' => 'Education']);
        }
        $this->registerAttendee($event)->update(['industry' => 'Retail']);
        $this->registerAttendee($event)->update(['industry' => 'Healthcare']);

        $this->actingAs($this->makeSuperAdmin())
            ->get(route('admin.events.speakers.report.preview', [$event, $speaker]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('audience.registered', 7)
                ->where('audience.industries', [
                    ['label' => 'Education', 'count' => 5],
                    ['label' => 'Other or not given', 'count' => 2],
                ]));
    }

    public function test_event_speakers_page_shows_connections_and_link_status(): void
    {
        $event = $this->makeEvent();
        $speaker = $this->speakerOn($event);
        $noEmail = $this->speakerOn($event, ['name' => 'No Email', 'contact_email' => null]);
        $this->connect($event, $speaker);
        $this->sendLink($event, $speaker);

        $this->actingAs($this->makeSuperAdmin())
            ->get(route('admin.events.speakers.index', $event))
            ->assertInertia(fn (Assert $page) => $page
                ->where('speakers.0.connections', 1)
                ->where('speakers.0.has_contact_email', true)
                ->where('speakers.0.report.state', 'active')
                ->where('speakers.1.has_contact_email', false)
                ->where('speakers.1.report.state', 'not_sent'));
        $this->assertNotNull($noEmail);
    }

    public function test_contact_email_is_saved_but_validated(): void
    {
        $admin = $this->makeSuperAdmin();
        $speaker = Speaker::factory()->create();

        $this->actingAs($admin)->put(route('admin.speakers.update', $speaker), [
            'name' => $speaker->name, 'contact_email' => 'not-an-email',
        ])->assertSessionHasErrors('contact_email');

        $this->actingAs($admin)->put(route('admin.speakers.update', $speaker), [
            'name' => $speaker->name, 'contact_email' => 'speaker@example.com',
        ])->assertSessionHasNoErrors();

        $this->assertSame('speaker@example.com', $speaker->fresh()->contact_email);
        $this->actingAs($admin)->get(route('admin.speakers.edit', $speaker))
            ->assertInertia(fn (Assert $page) => $page->where('speaker.contact_email', 'speaker@example.com'));
    }
}
