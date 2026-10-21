<?php

namespace Tests\Feature\Speaker;

use App\Models\Event;
use App\Models\ProgrammeSession;
use App\Models\Resource;
use App\Models\Speaker;
use App\Models\User;
use App\Notifications\ResourceAvailable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesEventWorld;
use Tests\TestCase;

/** The speaker portal: schedule, profile, resources, green room, stage and report. */
class SpeakerPortalTest extends TestCase
{
    use CreatesEventWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.agora.app_id' => str_repeat('a1', 16)]);
        config(['services.agora.app_certificate' => str_repeat('b2', 16)]);
    }

    /** @return array{0: User, 1: Speaker} */
    private function speakerAccount(array $attributes = []): array
    {
        $user = User::factory()->create();
        $speaker = Speaker::factory()->create(['user_id' => $user->id, 'name' => 'Naledi Dube', ...$attributes]);

        return [$user, $speaker];
    }

    private function agoraEvent(array $attributes = []): Event
    {
        return $this->makeEvent([
            'livestream_provider' => 'agora',
            'livestream_channel_name' => 'power-of-data-main',
            ...$attributes,
        ]);
    }

    private function onLineUp(Event $event, Speaker $speaker): void
    {
        $event->speakers()->attach($speaker->id, ['sort_order' => 0]);
    }

    private function sessionFor(Event $event, Speaker $speaker, array $attributes = []): ProgrammeSession
    {
        $session = ProgrammeSession::factory()->create(['event_id' => $event->id, ...$attributes]);
        $session->speakers()->attach($speaker->id, ['sort_order' => 0]);

        return $session;
    }

    public function test_only_accounts_linked_to_a_speaker_can_open_the_portal(): void
    {
        $this->get(route('speaker.dashboard'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get(route('speaker.dashboard'))->assertForbidden();
    }

    public function test_the_schedule_lists_only_the_speakers_public_events_and_their_own_sessions(): void
    {
        [$user, $speaker] = $this->speakerAccount();
        $event = $this->agoraEvent(['name' => 'The Power of Data']);
        $draft = $this->makeEvent(['status' => 'draft', 'name' => 'Unannounced']);
        $this->makeEvent(['name' => 'Not theirs']);
        $this->onLineUp($event, $speaker);
        $this->onLineUp($draft, $speaker);

        $this->sessionFor($event, $speaker, ['title' => 'Keynote']);
        ProgrammeSession::factory()->create(['event_id' => $event->id, 'title' => 'Someone else’s panel']);

        $this->actingAs($user)->get(route('speaker.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Speaker/Dashboard')
                ->has('events', 1)
                ->where('events.0.name', 'The Power of Data')
                ->where('events.0.has_stage', true)
                ->has('events.0.sessions', 1)
                ->where('events.0.sessions.0.title', 'Keynote'));
    }

    public function test_a_speaker_edits_their_profile_and_a_new_photo_waits_for_approval(): void
    {
        Storage::fake('public');
        [$user, $speaker] = $this->speakerAccount(['photo_approved' => true, 'photo_path' => 'speakers/old.jpg']);
        Storage::disk('public')->put('speakers/old.jpg', 'x');

        $this->actingAs($user)->post(route('speaker.profile.update'), [
            'name' => 'Dr Naledi Dube',
            'title' => 'Chief Data Officer',
            'organisation' => 'DataWorks',
            'bio' => 'Naledi leads data at DataWorks.',
            'photo' => UploadedFile::fake()->image('me.jpg'),
            'photo_alt' => 'Naledi Dube smiling',
        ])->assertSessionHas('success');

        $speaker->refresh();
        $this->assertSame('Dr Naledi Dube', $speaker->name);
        $this->assertSame('Chief Data Officer', $speaker->title);
        $this->assertFalse($speaker->photo_approved);
        $this->assertNotSame('speakers/old.jpg', $speaker->photo_path);
        Storage::disk('public')->assertMissing('speakers/old.jpg');
        $this->assertDatabaseHas('audit_logs', ['action' => 'speaker.profile_updated', 'subject_id' => $speaker->id]);
    }

    public function test_a_shared_file_is_published_at_once_and_shown_to_attendees_with_the_speakers_name(): void
    {
        Storage::fake('local');
        [$user, $speaker] = $this->speakerAccount();
        $event = $this->makeEvent();
        $this->onLineUp($event, $speaker);

        $this->actingAs($user)->post(route('speaker.events.resources.store', $event), [
            'title' => 'Slides',
            'category' => 'after_event',
            'file' => UploadedFile::fake()->create('slides.pdf', 100, 'application/pdf'),
            'release' => 'now',
        ])->assertSessionHas('success');

        $resource = Resource::sole();
        $this->assertSame($speaker->id, $resource->speaker_id);
        $this->assertTrue($resource->is_published);
        Storage::disk('local')->assertExists($resource->file_path);

        $attendee = $this->registerAttendee($event)->user;
        $this->actingAs($attendee)->get(route('resources.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('resources', 1)
                ->where('resources.0.title', 'Slides')
                ->where('resources.0.shared_by', 'Naledi Dube'));
    }

    public function test_a_resource_released_after_the_session_stays_hidden_until_it_ends(): void
    {
        [$user, $speaker] = $this->speakerAccount();
        $event = $this->makeEvent();
        $this->onLineUp($event, $speaker);
        $session = $this->sessionFor($event, $speaker, ['start_time' => now()->addDay(), 'end_time' => now()->addDay()->addHour()]);

        $this->actingAs($user)->post(route('speaker.events.resources.store', $event), [
            'title' => 'Paper',
            'category' => 'after_event',
            'external_url' => 'https://example.com/paper',
            'release' => (string) $session->id,
        ])->assertSessionHas('success');

        $resource = Resource::sole();
        $attendee = $this->registerAttendee($event)->user;

        $this->actingAs($attendee)->get(route('resources.index'))->assertInertia(fn (Assert $page) => $page->has('resources', 0));
        $this->actingAs($attendee)->get(route('resources.show', $resource))->assertNotFound();

        $this->travel(2)->days();
        $this->actingAs($attendee)->get(route('resources.index'))->assertInertia(fn (Assert $page) => $page->has('resources', 1));
        $this->actingAs($attendee)->get(route('resources.show', $resource))->assertRedirect('https://example.com/paper');
    }

    public function test_speaker_uploads_do_not_email_every_attendee(): void
    {
        Notification::fake();
        [$user, $speaker] = $this->speakerAccount();
        $event = $this->makeEvent();
        $this->onLineUp($event, $speaker);
        $this->registerAttendee($event);

        $this->actingAs($user)->post(route('speaker.events.resources.store', $event), [
            'title' => 'Paper', 'category' => 'after_event',
            'external_url' => 'https://example.com/paper', 'release' => 'now',
        ]);

        $this->artisan('resources:release')->assertSuccessful();

        Notification::assertNotSentTo(User::all(), ResourceAvailable::class);
    }

    public function test_a_speaker_cannot_touch_another_speakers_resource_or_an_event_they_are_not_on(): void
    {
        [$user, $speaker] = $this->speakerAccount();
        $event = $this->makeEvent();
        $elsewhere = $this->makeEvent();
        $this->onLineUp($event, $speaker);

        $theirs = Resource::factory()->create(['event_id' => $event->id, 'speaker_id' => Speaker::factory()->create()->id]);

        $this->actingAs($user)->delete(route('speaker.events.resources.destroy', [$event, $theirs]))->assertNotFound();
        $this->assertModelExists($theirs);

        $this->actingAs($user)->get(route('speaker.events.resources.index', $elsewhere))->assertNotFound();
        $this->actingAs($user)->get(route('speaker.events.report', $elsewhere))->assertNotFound();
        $this->actingAs($user)->post(route('speaker.events.green-room.credentials', $elsewhere))->assertNotFound();
    }

    public function test_green_room_credentials_are_for_a_separate_channel_with_a_loopback_monitor(): void
    {
        [$user, $speaker] = $this->speakerAccount();
        $event = $this->agoraEvent();
        $this->onLineUp($event, $speaker);

        $this->actingAs($user)->get(route('speaker.events.green-room', $event))
            ->assertInertia(fn (Assert $page) => $page->component('Speaker/GreenRoom')->where('available', true));

        $response = $this->actingAs($user)->postJson(route('speaker.events.green-room.credentials', $event))->assertOk();

        $this->assertSame('power-of-data-main--greenroom', $response->json('channel'));
        $this->assertSame(100_000 + $speaker->id, $response->json('uid'));
        $this->assertSame('power-of-data-main--greenroom', $response->json('monitor.channel'));
        $this->assertGreaterThanOrEqual(1_000_000_000, $response->json('monitor.uid'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'speaker.greenroom_token_issued', 'subject_id' => $speaker->id]);
    }

    public function test_events_not_on_the_platform_stage_have_no_green_room(): void
    {
        [$user, $speaker] = $this->speakerAccount();
        $event = $this->makeEvent(['livestream_provider' => 'youtube']);
        $this->onLineUp($event, $speaker);

        $this->actingAs($user)->get(route('speaker.events.green-room', $event))
            ->assertInertia(fn (Assert $page) => $page->where('available', false));
        $this->actingAs($user)->postJson(route('speaker.events.green-room.credentials', $event))->assertNotFound();
    }

    public function test_staff_can_join_the_green_room_from_the_broadcast_console(): void
    {
        $event = $this->agoraEvent();
        $admin = $this->makeSuperAdmin();

        $this->actingAs($admin)->postJson(route('admin.live.broadcast.token', $event).'?greenroom=1')
            ->assertOk()
            ->assertJsonPath('channel', 'power-of-data-main--greenroom');

        $this->actingAs($admin)->postJson(route('admin.live.broadcast.token', $event))
            ->assertOk()
            ->assertJsonPath('channel', 'power-of-data-main');
    }

    public function test_the_signed_in_stage_matches_the_stage_link(): void
    {
        [$user, $speaker] = $this->speakerAccount();
        $event = $this->agoraEvent();
        $this->onLineUp($event, $speaker);

        $this->actingAs($user)->get(route('speaker.events.stage', $event))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Public/Stage')
                ->where('speaker.name', 'Naledi Dube')
                ->where('endpoints.credentials', route('speaker.events.stage.credentials', $event))
                ->where('backUrl', route('speaker.dashboard')));

        $this->actingAs($user)->postJson(route('speaker.events.stage.credentials', $event))
            ->assertOk()
            ->assertJsonPath('channel', 'power-of-data-main')
            ->assertJsonPath('uid', 100_000 + $speaker->id);

        $this->actingAs($user)->postJson(route('speaker.events.stage.broadcasting-auth', $event), [
            'socket_id' => '123.456', 'channel_name' => "private-admin.event.{$event->id}",
        ])->assertForbidden();
    }

    public function test_the_report_shows_connections_and_its_csv_download_is_audited(): void
    {
        [$user, $speaker] = $this->speakerAccount();
        $event = $this->makeEvent();
        $this->onLineUp($event, $speaker);

        $attendee = $this->registerAttendee($event, 'online', ['name' => 'Thabo Mokoena'])->user;
        $this->actingAs($attendee)->post(route('speakers.connect', [$event, $speaker]), ['note' => 'Hi']);

        $this->actingAs($user)->get(route('speaker.events.report', $event))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Public/SpeakerReport')
                ->has('contacts', 1)
                ->where('contacts.0.name', 'Thabo Mokoena'));

        $csv = $this->actingAs($user)->get(route('speaker.events.report.contacts', $event))->assertOk();
        $this->assertStringContainsString('Thabo Mokoena', $csv->streamedContent());
        $this->assertDatabaseHas('audit_logs', ['action' => 'speaker.contacts_downloaded', 'subject_id' => $speaker->id]);
    }

    public function test_a_speaker_on_the_line_up_can_watch_live_without_registering(): void
    {
        [$user, $speaker] = $this->speakerAccount();
        $event = $this->makeEvent();
        $this->onLineUp($event, $speaker);

        $this->actingAs($user)->get(route('live.show'))->assertOk();

        // Not on the line-up: still needs a registration.
        [$otherUser] = $this->speakerAccount(['name' => 'Someone Else']);
        $this->actingAs($otherUser)->get(route('live.show'))->assertForbidden();
    }
}
