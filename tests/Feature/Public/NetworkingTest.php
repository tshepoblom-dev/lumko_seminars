<?php

namespace Tests\Feature\Public;

use App\Enums\ConsentType;
use App\Enums\UserRole;
use App\Models\AttendeeConsent;
use App\Models\Connection;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\User;
use App\Notifications\ConnectionAccepted;
use App\Notifications\ConnectionRequested;
use App\Services\ConsentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class NetworkingTest extends TestCase
{
    use RefreshDatabase;

    private function registerWithConsent(Event $event, string $mode, bool $consent = true): EventRegistration
    {
        $user = User::factory()->create(['role' => UserRole::Attendee, 'email_verified_at' => now()]);
        $registration = $user->registrations()->create(['event_id' => $event->id, 'attendance_mode' => $mode, 'status' => 'registered']);

        if ($consent) {
            AttendeeConsent::create([
                'user_id' => $user->id, 'event_id' => $event->id, 'type' => 'networking', 'granted_at' => now(),
            ]);
        }

        return $registration->fresh();
    }

    public function test_directory_includes_both_online_and_in_person_opted_in_attendees(): void
    {
        $event = Event::factory()->create(['status' => 'published']);
        $me = $this->registerWithConsent($event, 'online');
        $this->registerWithConsent($event, 'online');
        $this->registerWithConsent($event, 'in_person');
        $this->registerWithConsent($event, 'in_person', consent: false);

        $response = $this->actingAs($me->user)->get(route('networking.index'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Public/Networking')
            ->has('directory', 2)
        );
    }

    public function test_directory_never_exposes_email_mobile_or_internal_ids(): void
    {
        $event = Event::factory()->create(['status' => 'published']);
        $me = $this->registerWithConsent($event, 'online');
        $this->registerWithConsent($event, 'in_person');

        $response = $this->actingAs($me->user)->get(route('networking.index'));

        $response->assertInertia(fn (Assert $page) => $page
            ->component('Public/Networking')
            ->has('directory.0', fn (Assert $entry) => $entry
                ->hasAll(['registration_id', 'name', 'organisation', 'job_title', 'attendance_mode', 'attendance_mode_label', 'interests', 'connection'])
                ->missing('email')
                ->missing('mobile')
                ->missing('user_id')
            )
        );
    }

    public function test_user_can_send_and_recipient_can_accept_connection_request(): void
    {
        $event = Event::factory()->create(['status' => 'published']);
        $requester = $this->registerWithConsent($event, 'online');
        $recipient = $this->registerWithConsent($event, 'in_person');

        $this->actingAs($requester->user)->post(route('networking.request', $recipient->public_id));

        $connection = Connection::first();
        $this->assertSame('pending', $connection->status->value);

        $this->actingAs($recipient->user)->post(route('networking.accept', $connection->id));

        $this->assertSame('accepted', $connection->fresh()->status->value);
    }

    public function test_requester_cannot_accept_their_own_request(): void
    {
        $event = Event::factory()->create(['status' => 'published']);
        $requester = $this->registerWithConsent($event, 'online');
        $recipient = $this->registerWithConsent($event, 'online');

        $this->actingAs($requester->user)->post(route('networking.request', $recipient->public_id));
        $connection = Connection::first();

        $response = $this->actingAs($requester->user)->post(route('networking.accept', $connection->id));

        $response->assertNotFound();
    }

    public function test_attendee_without_networking_consent_is_excluded_from_directory(): void
    {
        $event = Event::factory()->create(['status' => 'published']);
        $me = $this->registerWithConsent($event, 'online');
        $this->registerWithConsent($event, 'online', consent: false);

        $response = $this->actingAs($me->user)->get(route('networking.index'));

        $response->assertInertia(fn (Assert $page) => $page
            ->component('Public/Networking')
            ->has('directory', 0)
        );
    }

    public function test_directory_exposes_connection_id_for_pending_incoming_request(): void
    {
        $event = Event::factory()->create(['status' => 'published']);
        $requester = $this->registerWithConsent($event, 'online');
        $recipient = $this->registerWithConsent($event, 'in_person');

        $this->actingAs($requester->user)->post(route('networking.request', $recipient->public_id));
        $connection = Connection::first();

        $response = $this->actingAs($recipient->user)->get(route('networking.index'));

        $response->assertInertia(fn (Assert $page) => $page
            ->component('Public/Networking')
            ->where('directory.0.connection.id', $connection->id)
            ->where('directory.0.connection.i_requested', false)
        );
    }

    // B9 guardrails

    public function test_cannot_request_connection_with_self(): void
    {
        $event = Event::factory()->create(['status' => 'published']);
        $me = $this->registerWithConsent($event, 'online');

        $response = $this->actingAs($me->user)->post(route('networking.request', $me->public_id));

        $response->assertStatus(422);
        $this->assertSame(0, Connection::count());
    }

    public function test_duplicate_request_does_not_create_a_second_row(): void
    {
        $event = Event::factory()->create(['status' => 'published']);
        $requester = $this->registerWithConsent($event, 'online');
        $recipient = $this->registerWithConsent($event, 'online');

        $this->actingAs($requester->user)->post(route('networking.request', $recipient->public_id));
        $this->actingAs($requester->user)->post(route('networking.request', $recipient->public_id));

        $this->assertSame(1, Connection::count());
    }

    public function test_reverse_request_surfaces_existing_incoming_request_instead_of_duplicating(): void
    {
        $event = Event::factory()->create(['status' => 'published']);
        $a = $this->registerWithConsent($event, 'online');
        $b = $this->registerWithConsent($event, 'online');

        $this->actingAs($a->user)->post(route('networking.request', $b->public_id));
        $this->actingAs($b->user)->post(route('networking.request', $a->public_id));

        $this->assertSame(1, Connection::count());
    }

    public function test_cannot_request_a_registration_from_a_different_event(): void
    {
        $eventA = Event::factory()->create(['status' => 'published']);
        $eventB = Event::factory()->create(['status' => 'published']);
        $me = $this->registerWithConsent($eventA, 'online');
        $other = $this->registerWithConsent($eventB, 'online');

        $response = $this->actingAs($me->user)->post(route('networking.request', $other->public_id));

        $response->assertNotFound();
        $this->assertSame(0, Connection::count());
    }

    public function test_cannot_request_a_non_opted_in_attendee(): void
    {
        $event = Event::factory()->create(['status' => 'published']);
        $me = $this->registerWithConsent($event, 'online');
        $notOptedIn = $this->registerWithConsent($event, 'online', consent: false);

        $response = $this->actingAs($me->user)->post(route('networking.request', $notOptedIn->public_id));

        $response->assertStatus(422);
        $this->assertSame(0, Connection::count());
    }

    public function test_unknown_registration_id_returns_404_not_500(): void
    {
        $event = Event::factory()->create(['status' => 'published']);
        $me = $this->registerWithConsent($event, 'online');

        $response = $this->actingAs($me->user)->post(route('networking.request', '01ARZ3NDEKTSV4RRFFQ69G5FAV'));

        $response->assertNotFound();
    }

    // D-Networking completion
    public function test_requester_can_withdraw_a_pending_request(): void
    {
        $event = Event::factory()->create(['status' => 'published']);
        $requester = $this->registerWithConsent($event, 'online');
        $recipient = $this->registerWithConsent($event, 'online');

        $this->actingAs($requester->user)->post(route('networking.request', $recipient->public_id));
        $connection = Connection::first();

        $response = $this->actingAs($requester->user)->delete(route('networking.withdraw', $connection->id));

        $response->assertRedirect();
        $this->assertSame(0, Connection::count());
    }

    public function test_recipient_cannot_withdraw_a_request_they_did_not_send(): void
    {
        $event = Event::factory()->create(['status' => 'published']);
        $requester = $this->registerWithConsent($event, 'online');
        $recipient = $this->registerWithConsent($event, 'online');

        $this->actingAs($requester->user)->post(route('networking.request', $recipient->public_id));
        $connection = Connection::first();

        $response = $this->actingAs($recipient->user)->delete(route('networking.withdraw', $connection->id));

        $response->assertNotFound();
        $this->assertSame(1, Connection::count());
    }

    public function test_either_party_can_remove_an_accepted_connection(): void
    {
        $event = Event::factory()->create(['status' => 'published']);
        $requester = $this->registerWithConsent($event, 'online');
        $recipient = $this->registerWithConsent($event, 'online');

        $this->actingAs($requester->user)->post(route('networking.request', $recipient->public_id));
        $connection = Connection::first();
        $this->actingAs($recipient->user)->post(route('networking.accept', $connection->id));

        $response = $this->actingAs($recipient->user)->delete(route('networking.remove', $connection->id));

        $response->assertRedirect();
        $this->assertSame(0, Connection::count());
    }

    public function test_accepted_connection_and_incoming_request_appear_independent_of_directory_opt_in(): void
    {
        $event = Event::factory()->create(['status' => 'published']);
        $requester = $this->registerWithConsent($event, 'online');
        $recipient = $this->registerWithConsent($event, 'online');
        $pendingRequester = $this->registerWithConsent($event, 'online');

        $this->actingAs($requester->user)->post(route('networking.request', $recipient->public_id));
        $connection = Connection::first();
        $this->actingAs($recipient->user)->post(route('networking.accept', $connection->id));
        $this->actingAs($pendingRequester->user)->post(route('networking.request', $recipient->public_id));

        // Recipient withdraws networking consent entirely (hides them from
        // the directory) but should still see the accepted connection and
        // the still-pending incoming request.
        app(ConsentService::class)->withdraw($recipient->user, ConsentType::Networking, $event, source: 'account');

        $response = $this->actingAs($recipient->user)->get(route('networking.index'));

        $response->assertInertia(fn ($page) => $page
            ->component('Public/Networking')
            ->where('iOptedIn', false)
            ->has('directory', 0)
            ->has('myConnections', 1)
            ->has('incomingRequests', 1)
        );
    }

    public function test_request_and_accept_send_in_app_notifications(): void
    {
        Notification::fake();

        $event = Event::factory()->create(['status' => 'published']);
        $requester = $this->registerWithConsent($event, 'online');
        $recipient = $this->registerWithConsent($event, 'online');

        $this->actingAs($requester->user)->post(route('networking.request', $recipient->public_id));
        Notification::assertSentTo($recipient->user, ConnectionRequested::class);

        $connection = Connection::first();
        $this->actingAs($recipient->user)->post(route('networking.accept', $connection->id));
        Notification::assertSentTo($requester->user, ConnectionAccepted::class);
    }
}
