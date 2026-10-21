<?php

namespace Tests\Concerns;

use App\Enums\UserRole;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Payment;
use App\Models\Ticket;
use App\Models\User;

/**
 * Stage G — one place to build the recurring "an event with staff and
 * registered attendees" scenarios, so tests state intent
 * (`$this->registerAttendee($event)`) instead of repeating factory setup.
 */
trait CreatesEventWorld
{
    protected function makeEvent(array $attributes = []): Event
    {
        return Event::factory()->create([
            'status' => 'published',
            'starts_at' => now()->addWeek(),
            'ends_at' => now()->addWeek()->addHours(2),
            ...$attributes,
        ]);
    }

    protected function makeSuperAdmin(): User
    {
        return User::factory()->create(['role' => UserRole::SuperAdmin]);
    }

    protected function makeManagerFor(Event $event): User
    {
        $manager = User::factory()->create(['role' => UserRole::EventManager]);
        $event->managers()->attach($manager->id, ['role' => 'event_manager']);

        return $manager;
    }

    protected function registerAttendee(Event $event, string $mode = 'online', array $userAttributes = []): EventRegistration
    {
        $user = User::factory()->create(['role' => UserRole::Attendee, ...$userAttributes]);

        return EventRegistration::factory()->create([
            'event_id' => $event->id,
            'user_id' => $user->id,
            'attendance_mode' => $mode,
        ]);
    }

    /**
     * An in-person attendee whose payment is approved and ticket confirmed
     * (or both left pending when $approved is false).
     *
     * @return array{registration: EventRegistration, payment: Payment, ticket: Ticket}
     */
    protected function registerPaidInPersonAttendee(Event $event, bool $approved = true): array
    {
        $registration = $this->registerAttendee($event, 'in_person');

        $payment = Payment::factory()->create(['event_registration_id' => $registration->id]);
        $ticket = Ticket::factory()->create(['event_registration_id' => $registration->id]);

        if ($approved) {
            $payment->update(['status' => 'approved', 'reviewed_at' => now()]);
            $ticket->update(['status' => 'confirmed', 'issued_at' => now()]);
        }

        return ['registration' => $registration, 'payment' => $payment->fresh(), 'ticket' => $ticket->fresh()];
    }
}
