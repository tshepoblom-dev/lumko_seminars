<?php

namespace Tests\Feature;

use App\Actions\AccountAnonymiser;
use App\Enums\ConsentType;
use App\Models\ConsentRecord;
use App\Models\Event;
use App\Models\Speaker;
use App\Models\SpeakerConnection;
use App\Services\ConsentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesEventWorld;
use Tests\TestCase;

class SpeakerConnectionTest extends TestCase
{
    use CreatesEventWorld, RefreshDatabase;

    private function speakerOn(Event $event, array $attributes = []): Speaker
    {
        $speaker = Speaker::factory()->create(['name' => 'Naledi Dube', 'organisation' => 'DataWorks', ...$attributes]);
        $event->speakers()->attach($speaker->id, ['sort_order' => 0]);

        return $speaker;
    }

    public function test_connecting_records_the_exact_consent_and_the_note(): void
    {
        $event = $this->makeEvent();
        $speaker = $this->speakerOn($event);
        $attendee = $this->registerAttendee($event)->user;

        $this->actingAs($attendee)
            ->post(route('speakers.connect', [$event, $speaker]), ['note' => 'Data governance for municipalities'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $record = ConsentRecord::sole();
        $this->assertSame('speaker_contact', $record->type);
        $this->assertSame('granted', $record->action);
        $this->assertSame($speaker->id, $record->speaker_id);
        $this->assertSame($event->id, $record->event_id);
        $this->assertNotNull($record->effective_at);
        $this->assertStringContainsString('Naledi Dube (DataWorks)', $record->purpose_text);
        $this->assertStringNotContainsString(':speaker', $record->purpose_text);

        $connection = SpeakerConnection::sole();
        $this->assertTrue($connection->isActive());
        $this->assertSame('Data governance for municipalities', $connection->note);
        $this->assertTrue(app(ConsentService::class)->isGranted($attendee, ConsentType::SpeakerContact, $event, $speaker));
    }

    public function test_connecting_twice_updates_the_note_without_a_second_consent(): void
    {
        $event = $this->makeEvent();
        $speaker = $this->speakerOn($event);
        $attendee = $this->registerAttendee($event)->user;

        $this->actingAs($attendee)->post(route('speakers.connect', [$event, $speaker]), ['note' => 'first']);
        $this->actingAs($attendee)->post(route('speakers.connect', [$event, $speaker]), ['note' => 'second']);

        $this->assertSame(1, ConsentRecord::count());
        $this->assertSame('second', SpeakerConnection::sole()->note);
    }

    public function test_a_speaker_not_on_the_event_or_an_unregistered_attendee_is_refused(): void
    {
        $event = $this->makeEvent();
        $other = $this->makeEvent();
        $speakerElsewhere = $this->speakerOn($other);
        $speaker = $this->speakerOn($event);
        $attendee = $this->registerAttendee($event)->user;

        $this->actingAs($attendee)->post(route('speakers.connect', [$event, $speakerElsewhere]))->assertNotFound();
        // Registered for $event only, so $other doesn't exist as far as they know.
        $this->actingAs($attendee)->post(route('speakers.connect', [$other, $speakerElsewhere]))->assertNotFound();

        $this->assertSame(0, SpeakerConnection::count());
        $this->assertSame(0, ConsentRecord::count());
        $this->assertNotNull($speaker);
    }

    public function test_staff_cannot_connect(): void
    {
        $event = $this->makeEvent();
        $speaker = $this->speakerOn($event);

        $this->actingAs($this->makeSuperAdmin())->post(route('speakers.connect', [$event, $speaker]))->assertForbidden();
    }

    public function test_connecting_closes_with_the_feedback_window(): void
    {
        config(['seminar.feedback_days' => 30]);
        $event = $this->makeEvent([
            'status' => 'completed',
            'starts_at' => now()->subDays(41),
            'ends_at' => now()->subDays(40),
        ]);
        $speaker = $this->speakerOn($event);
        $attendee = $this->registerAttendee($event)->user;

        $this->actingAs($attendee)->post(route('speakers.connect', [$event, $speaker]))->assertSessionHas('error');

        $this->assertSame(0, SpeakerConnection::count());
    }

    public function test_withdrawing_from_the_account_page_works_after_the_event_and_only_for_your_own(): void
    {
        $event = $this->makeEvent();
        $speaker = $this->speakerOn($event);
        $attendee = $this->registerAttendee($event)->user;
        $someoneElse = $this->registerAttendee($event)->user;
        $this->actingAs($attendee)->post(route('speakers.connect', [$event, $speaker]));
        $connection = SpeakerConnection::sole();

        $this->actingAs($someoneElse)
            ->delete(route('attendee.speaker-connections.destroy', $connection))
            ->assertNotFound();
        $this->assertTrue($connection->fresh()->isActive());

        $event->update(['status' => 'archived']);

        $this->actingAs($attendee)
            ->delete(route('attendee.speaker-connections.destroy', $connection))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertFalse($connection->fresh()->isActive());
        $this->assertDatabaseHas('consent_records', ['type' => 'speaker_contact', 'action' => 'withdrawn', 'speaker_id' => $speaker->id]);
    }

    public function test_feedback_form_connects_each_ticked_speaker_of_the_event_only(): void
    {
        $event = $this->makeEvent(['starts_at' => now()->subDay()->subHours(2), 'ends_at' => now()->subDay()]);
        $first = $this->speakerOn($event, ['name' => 'First Speaker']);
        $second = $this->speakerOn($event, ['name' => 'Second Speaker']);
        $elsewhere = $this->speakerOn($this->makeEvent());
        $attendee = $this->registerAttendee($event)->user;

        $this->actingAs($attendee)->get(route('feedback.create'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('speakers', 2)
                ->where('speakers.0.connected', false)
                ->where('speakers.0.consent_text', fn ($text) => str_contains($text, 'First Speaker')));

        $this->actingAs($attendee)->post(route('feedback.store'), [
            'rating' => 5,
            'speaker_ids' => [$first->id, $elsewhere->id],
        ])->assertRedirect(route('feedback.thanks'));

        $this->assertSame([$first->id], SpeakerConnection::active()->pluck('speaker_id')->all());
        $this->assertDatabaseHas('consent_records', ['speaker_id' => $first->id, 'source' => 'feedback']);
        $this->assertNotNull($second);
    }

    public function test_dashboard_offers_connect_without_exposing_the_speakers_email(): void
    {
        $event = $this->makeEvent();
        $speaker = $this->speakerOn($event, ['contact_email' => 'private-speaker@example.com']);
        $attendee = $this->registerAttendee($event)->user;
        $this->actingAs($attendee)->post(route('speakers.connect', [$event, $speaker]));

        $response = $this->actingAs($attendee)->get(route('attendee.dashboard'));

        $response->assertInertia(fn (Assert $page) => $page
            ->where('speakers.0.id', $speaker->id)
            ->where('speakers.0.connected', true)
            ->where('speakerConnect.event_id', $event->id)
            ->where('speakerConnect.open', true));
        $this->assertStringNotContainsString('private-speaker@example.com', $response->getContent());

        $this->get('/')->assertDontSee('private-speaker@example.com');
    }

    public function test_account_page_lists_connections_and_the_data_export_includes_them(): void
    {
        $event = $this->makeEvent();
        $speaker = $this->speakerOn($event);
        $attendee = $this->registerAttendee($event)->user;
        $this->actingAs($attendee)->post(route('speakers.connect', [$event, $speaker]), ['note' => 'Hello']);

        $this->actingAs($attendee)->get(route('attendee.preferences.edit'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('speakerConnections', 1)
                ->where('speakerConnections.0.speaker', 'Naledi Dube'));

        $this->actingAs($attendee)->get(route('attendee.preferences.download'))
            ->assertJsonPath('speaker_connections.0.speaker', 'Naledi Dube')
            ->assertJsonPath('speaker_connections.0.note', 'Hello');
    }

    public function test_deleting_the_account_removes_them_from_every_speaker_list(): void
    {
        $event = $this->makeEvent();
        $speaker = $this->speakerOn($event);
        $attendee = $this->registerAttendee($event)->user;
        $this->actingAs($attendee)->post(route('speakers.connect', [$event, $speaker]), ['note' => 'Personal note']);

        app(AccountAnonymiser::class)->anonymise($attendee->fresh());

        $connection = SpeakerConnection::sole();
        $this->assertFalse($connection->isActive());
        $this->assertNull($connection->note);
    }
}
