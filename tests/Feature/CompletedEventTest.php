<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Notifications\PostEventThankYou;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesEventWorld;
use Tests\TestCase;

/**
 * Staff mark an event `completed` once it is over. That must NOT lock
 * attendees out of the portal or skip the thank-you: only `published` and
 * `completed` events are publicly visible, while draft/archived stay hidden
 * and registration stays published-only.
 */
class CompletedEventTest extends TestCase
{
    use CreatesEventWorld, RefreshDatabase;

    private function endedEvent(string $status, array $extra = []): Event
    {
        return $this->makeEvent([
            'status' => $status,
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->subDay(),
            'recording_url' => 'https://example.com/recording',
            ...$extra,
        ]);
    }

    public function test_home_page_keeps_showing_a_completed_event_with_its_recording(): void
    {
        $this->endedEvent('completed');

        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('event.has_ended', true)
            ->where('event.recording_url', 'https://example.com/recording')
        );
    }

    public function test_attendees_keep_access_to_live_resources_and_feedback_after_completion(): void
    {
        $event = $this->endedEvent('completed');
        $attendee = $this->registerAttendee($event)->user;

        $this->actingAs($attendee)->get(route('live.show'))->assertOk();
        $this->actingAs($attendee)->get(route('resources.index'))->assertOk();
        $this->actingAs($attendee)->get(route('feedback.create'))->assertOk();
    }

    public function test_thank_you_is_still_sent_when_the_event_was_marked_completed_first(): void
    {
        Notification::fake();
        $event = $this->endedEvent('completed');
        $attendee = $this->registerAttendee($event)->user;

        $this->artisan('postevent:send')->assertSuccessful();

        Notification::assertSentToTimes($attendee, PostEventThankYou::class, 1);
    }

    public function test_registration_stays_closed_for_a_completed_event(): void
    {
        $this->makeEvent(['status' => 'completed', 'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(2)]);

        $this->get(route('event.register'))->assertInertia(fn (Assert $page) => $page->component('Public/RegistrationClosed'));
    }

    public function test_draft_and_archived_events_stay_hidden_from_the_portal(): void
    {
        foreach (['draft', 'archived'] as $status) {
            $event = $this->endedEvent($status, ['slug' => "hidden-{$status}"]);
            $attendee = $this->registerAttendee($event)->user;

            $this->actingAs($attendee)->get(route('live.show'))->assertNotFound();
        }

        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('event', null));
    }
}
