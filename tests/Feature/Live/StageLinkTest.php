<?php

namespace Tests\Feature\Live;

use App\Enums\UserRole;
use App\Models\Event;
use App\Models\Speaker;
use App\Models\User;
use App\Support\AgoraStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Remote speaker stage links: created on the Broadcast console, used without an account. */
class StageLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.agora.app_id' => str_repeat('a1', 16)]);
        config(['services.agora.app_certificate' => str_repeat('b2', 16)]);
    }

    private function agoraEvent(array $attributes = []): Event
    {
        return Event::factory()->create([
            'status' => 'published',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHours(2),
            'livestream_provider' => 'agora',
            'livestream_channel_name' => 'power-of-data-main',
            ...$attributes,
        ]);
    }

    private function linkedSpeaker(Event $event): Speaker
    {
        $speaker = Speaker::factory()->create(['name' => 'Kgotla Paledi']);
        $event->speakers()->attach($speaker->id, ['sort_order' => 0]);

        return $speaker;
    }

    private function createLink(Event $event, Speaker $speaker, ?User $as = null): string
    {
        $as ??= User::factory()->create(['role' => UserRole::SuperAdmin]);

        $response = $this->actingAs($as)->postJson(route('admin.live.broadcast.stage-link.store', [$event, $speaker]));
        $response->assertOk();

        // The speaker has no account.
        $this->asGuest();

        return $response->json('url');
    }

    /**
     * The next request comes from a signed-out visitor. Tests reuse one app
     * instance across requests, so the admin request's workspace share
     * (ShareEventWorkspace) and signed-in guard are cleared by hand; in
     * production every request starts fresh.
     */
    private function asGuest(): void
    {
        $this->app['auth']->forgetGuards();
        Inertia::flushShared();
    }

    private function tokenFrom(string $url): string
    {
        return basename(parse_url($url, PHP_URL_PATH));
    }

    public function test_a_stage_link_opens_the_stage_and_issues_speaker_publisher_credentials(): void
    {
        $event = $this->agoraEvent();
        $speaker = $this->linkedSpeaker($event);

        $url = $this->createLink($event, $speaker);
        $token = $this->tokenFrom($url);

        // Only the hash is stored.
        $stored = DB::table('event_speaker')->where('speaker_id', $speaker->id)->value('stage_token_hash');
        $this->assertSame(hash('sha256', $token), $stored);
        $this->assertDatabaseHas('audit_logs', ['action' => 'speaker.stage_link_created', 'subject_id' => $speaker->id]);

        $this->get(route('stage.show', $token))
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Public/Stage')
                ->where('speaker.name', 'Kgotla Paledi')
                ->where('event.name', $event->name));

        $credentials = $this->postJson(route('stage.credentials', $token))->assertOk();

        $credentials->assertJsonStructure(['appId', 'channel', 'uid', 'token', 'screenUid', 'screenToken', 'expiresAt', 'screenUidOffset', 'audienceUidMin']);
        $this->assertSame(AgoraStage::SPEAKER_UID_BASE + $speaker->id, $credentials->json('uid'));
        $this->assertSame(AgoraStage::SCREEN_UID_OFFSET + AgoraStage::SPEAKER_UID_BASE + $speaker->id, $credentials->json('screenUid'));
        $this->assertNotSame($credentials->json('token'), $credentials->json('screenToken'));
        // Short-lived, so a withdrawn link takes the speaker off quickly.
        $this->assertTrue(now()->addMinutes(AgoraStage::SPEAKER_TOKEN_TTL_MINUTES + 1)->greaterThan($credentials->json('expiresAt')));
        $this->assertDatabaseHas('audit_logs', ['action' => 'speaker.stage_token_issued', 'subject_id' => $speaker->id]);
    }

    public function test_withdrawn_and_replaced_links_stop_working(): void
    {
        $event = $this->agoraEvent();
        $speaker = $this->linkedSpeaker($event);
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);

        $first = $this->tokenFrom($this->createLink($event, $speaker, $admin));
        $second = $this->tokenFrom($this->createLink($event, $speaker, $admin));

        $this->get(route('stage.show', $first))->assertNotFound();
        $this->postJson(route('stage.credentials', $first))->assertNotFound();
        $this->get(route('stage.show', $second))->assertOk();

        $this->actingAs($admin)->delete(route('admin.live.broadcast.stage-link.destroy', [$event, $speaker]))->assertRedirect();
        $this->asGuest();

        $this->get(route('stage.show', $second))->assertNotFound();
        $this->postJson(route('stage.credentials', $second))->assertNotFound();
        $this->assertDatabaseHas('audit_logs', ['action' => 'speaker.stage_link_revoked', 'subject_id' => $speaker->id]);
    }

    public function test_an_unknown_token_is_a_plain_404(): void
    {
        $this->agoraEvent();

        $this->get(route('stage.show', str_repeat('x', 48)))->assertNotFound();
        $this->postJson(route('stage.credentials', str_repeat('x', 48)))->assertNotFound();
    }

    public function test_links_stop_working_once_the_event_is_over_or_stops_using_agora(): void
    {
        $event = $this->agoraEvent();
        $speaker = $this->linkedSpeaker($event);
        $token = $this->tokenFrom($this->createLink($event, $speaker));

        $event->update(['livestream_provider' => 'youtube', 'livestream_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ']);
        $this->get(route('stage.show', $token))->assertNotFound();

        $event->update(['livestream_provider' => 'agora', 'starts_at' => now()->subHours(6), 'ends_at' => now()->subHours(3)]);
        $this->get(route('stage.show', $token))->assertNotFound();
    }

    public function test_only_staff_assigned_to_the_event_can_manage_stage_links(): void
    {
        $event = $this->agoraEvent();
        $speaker = $this->linkedSpeaker($event);
        $unassigned = User::factory()->create(['role' => UserRole::EventManager]);
        $attendee = User::factory()->create(['role' => UserRole::Attendee]);

        $this->actingAs($unassigned)->postJson(route('admin.live.broadcast.stage-link.store', [$event, $speaker]))->assertForbidden();
        $this->actingAs($attendee)->postJson(route('admin.live.broadcast.stage-link.store', [$event, $speaker]))->assertForbidden();

        $assigned = User::factory()->create(['role' => UserRole::EventManager]);
        $event->managers()->attach($assigned->id, ['role' => 'event_manager']);
        $this->actingAs($assigned)->postJson(route('admin.live.broadcast.stage-link.store', [$event, $speaker]))->assertOk();
    }

    public function test_a_speaker_not_linked_to_the_event_gets_no_link(): void
    {
        $event = $this->agoraEvent();
        $stranger = Speaker::factory()->create();
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);

        $this->actingAs($admin)->postJson(route('admin.live.broadcast.stage-link.store', [$event, $stranger]))->assertNotFound();
    }

    public function test_the_broadcast_console_lists_speakers_with_their_link_state(): void
    {
        $event = $this->agoraEvent();
        $speaker = $this->linkedSpeaker($event);
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);

        $this->actingAs($admin)->get(route('admin.live.broadcast.show', $event))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Live/Broadcast')
                ->where('speakers.0.name', 'Kgotla Paledi')
                ->where('speakers.0.uid', AgoraStage::SPEAKER_UID_BASE + $speaker->id)
                ->where('speakers.0.has_stage_link', false));

        $this->createLink($event, $speaker, $admin);

        $this->actingAs($admin)->get(route('admin.live.broadcast.show', $event))
            ->assertInertia(fn (Assert $page) => $page->where('speakers.0.has_stage_link', true));
    }
}
