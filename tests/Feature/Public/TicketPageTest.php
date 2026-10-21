<?php

namespace Tests\Feature\Public;

use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The ticket page is the only place an in-person attendee gets their check-in
 * QR code. It once handed Inertia an HtmlString object, which serialises to
 * `{}` and rendered as the text "[object Object]" — invisible to every other
 * test because nothing looked at the prop's actual value.
 */
class TicketPageTest extends TestCase
{
    use RefreshDatabase;

    private function attendee(TicketStatus $status, string $mode = 'in_person'): User
    {
        $event = Event::factory()->create(['status' => 'published']);
        $user = User::factory()->create(['role' => UserRole::Attendee]);
        $registration = $user->registrations()->create([
            'event_id' => $event->id, 'attendance_mode' => $mode, 'status' => 'registered',
        ]);

        if ($mode === 'in_person') {
            $registration->ticket()->create(['code' => 'TICKET-CODE-123', 'status' => $status]);
        }

        return $user;
    }

    public function test_a_confirmed_ticket_is_sent_as_svg_markup_not_an_object(): void
    {
        $response = $this->actingAs($this->attendee(TicketStatus::Confirmed))->get(route('ticket.show'));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('confirmed', true)
            ->where('qrSvg', fn ($svg) => is_string($svg) && str_contains($svg, '<svg'))
            // The printable fallback for when the camera can't read the screen.
            ->where('code', 'TICKET-CODE-123'));
    }

    public function test_the_qr_prop_survives_json_encoding_as_a_string(): void
    {
        // What the browser actually receives: the page object, JSON-encoded
        // the way @inertia embeds it in the HTML.
        $response = $this->actingAs($this->attendee(TicketStatus::Confirmed))->get(route('ticket.show'));

        $page = json_decode(json_encode($response->viewData('page')), true);

        $this->assertIsString($page['props']['qrSvg']);
        $this->assertStringContainsString('<svg', $page['props']['qrSvg']);
    }

    public function test_a_pending_ticket_exposes_neither_qr_nor_code(): void
    {
        $this->actingAs($this->attendee(TicketStatus::Pending))->get(route('ticket.show'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('confirmed', false)
                ->where('qrSvg', null)
                ->where('code', null));
    }

    public function test_an_online_only_attendee_has_no_ticket_page(): void
    {
        $this->actingAs($this->attendee(TicketStatus::Confirmed, 'online'))
            ->get(route('ticket.show'))
            ->assertNotFound();
    }
}
