<?php

namespace Tests\Feature\Admin;

use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\CheckIn;
use App\Models\Event;
use App\Models\Ticket;
use App\Models\User;
use App\Support\TicketCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CheckInTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirmed_ticket_can_be_checked_in(): void
    {
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $event = Event::factory()->create(['status' => 'published']);
        $attendee = User::factory()->create(['role' => UserRole::Attendee]);
        $registration = $attendee->registrations()->create([
            'event_id' => $event->id, 'attendance_mode' => 'in_person', 'status' => 'registered',
        ]);
        $ticket = $registration->ticket()->create(['code' => 'checkin-code', 'status' => TicketStatus::Confirmed]);

        $response = $this->actingAs($admin)->post(route('admin.check-in.store', $event), ['code' => 'checkin-code']);

        $response->assertRedirect();
        $this->assertDatabaseHas('check_ins', ['ticket_id' => $ticket->id]);
    }

    // The PDF ticket prints the fallback code in groups of five; door staff
    // type it as they read it.
    public function test_a_code_typed_with_the_spaces_from_the_pdf_still_checks_in(): void
    {
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $event = Event::factory()->create(['status' => 'published']);
        $attendee = User::factory()->create(['role' => UserRole::Attendee]);
        $registration = $attendee->registrations()->create([
            'event_id' => $event->id, 'attendance_mode' => 'in_person', 'status' => 'registered',
        ]);
        $ticket = $registration->ticket()->create(['code' => 'Ab3dEfG7hJkL9mN', 'status' => TicketStatus::Confirmed]);

        $this->actingAs($admin)->post(route('admin.check-in.store', $event), ['code' => ' Ab3dE fG7hJ kL9mN '])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('check_ins', ['ticket_id' => $ticket->id]);
    }

    public function test_checking_in_twice_is_idempotent(): void
    {
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $event = Event::factory()->create(['status' => 'published']);
        $attendee = User::factory()->create(['role' => UserRole::Attendee]);
        $registration = $attendee->registrations()->create([
            'event_id' => $event->id, 'attendance_mode' => 'in_person', 'status' => 'registered',
        ]);
        $ticket = $registration->ticket()->create(['code' => 'dup-code', 'status' => TicketStatus::Confirmed]);

        $this->actingAs($admin)->post(route('admin.check-in.store', $event), ['code' => 'dup-code']);
        $this->actingAs($admin)->post(route('admin.check-in.store', $event), ['code' => 'dup-code']);

        $this->assertSame(1, CheckIn::where('ticket_id', $ticket->id)->count());
    }

    public function test_unconfirmed_ticket_cannot_be_checked_in(): void
    {
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $event = Event::factory()->create(['status' => 'published']);
        $attendee = User::factory()->create(['role' => UserRole::Attendee]);
        $registration = $attendee->registrations()->create([
            'event_id' => $event->id, 'attendance_mode' => 'in_person', 'status' => 'registered',
        ]);
        $registration->ticket()->create(['code' => 'unpaid-code', 'status' => TicketStatus::Pending]);

        $response = $this->actingAs($admin)->post(route('admin.check-in.store', $event), ['code' => 'unpaid-code']);

        $response->assertSessionHasErrors('code');
    }

    // T-CHK-2 — the raw code is encrypted at rest; lookups go via code_hash.
    public function test_ticket_code_is_encrypted_at_rest(): void
    {
        $event = Event::factory()->create(['status' => 'published']);
        $attendee = User::factory()->create(['role' => UserRole::Attendee]);
        $registration = $attendee->registrations()->create([
            'event_id' => $event->id, 'attendance_mode' => 'in_person', 'status' => 'registered',
        ]);
        $ticket = $registration->ticket()->create(['code' => 'secret-plaintext-code', 'status' => TicketStatus::Confirmed]);

        $raw = DB::table('tickets')->where('id', $ticket->id)->value('code');

        $this->assertNotSame('secret-plaintext-code', $raw);
        $this->assertStringNotContainsString('secret-plaintext-code', $raw);
        $this->assertSame('secret-plaintext-code', $ticket->fresh()->code);
    }

    // T-CHK-1/T-CHK-2 — a ticket from a different event is refused, not a 500.
    public function test_ticket_from_a_different_event_is_refused(): void
    {
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $currentEvent = Event::factory()->create(['status' => 'published']);
        $otherEvent = Event::factory()->create(['status' => 'published', 'starts_at' => now()->addMonth(), 'ends_at' => now()->addMonth()->addHours(2)]);

        $attendee = User::factory()->create(['role' => UserRole::Attendee]);
        $registration = $attendee->registrations()->create([
            'event_id' => $otherEvent->id, 'attendance_mode' => 'in_person', 'status' => 'registered',
        ]);
        $registration->ticket()->create(['code' => 'wrong-event-code', 'status' => TicketStatus::Confirmed]);

        // Check-in runs inside $currentEvent's workspace, so this ticket
        // belongs to a different event than the one being checked in.
        $response = $this->actingAs($admin)->post(route('admin.check-in.store', $currentEvent), ['code' => 'wrong-event-code']);

        $response->assertSessionHasErrors('code');
        $this->assertDatabaseMissing('check_ins', ['ticket_id' => Ticket::where('code_hash', TicketCode::hash('wrong-event-code'))->first()->id]);
    }
}
