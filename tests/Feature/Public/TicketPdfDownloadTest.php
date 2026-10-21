<?php

namespace Tests\Feature\Public;

use App\Models\ProgrammeSession;
use App\Models\User;
use App\Services\TicketPdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesEventWorld;
use Tests\TestCase;

/** The PDF ticket, downloadable from the ticket page and the dashboard. */
class TicketPdfDownloadTest extends TestCase
{
    use CreatesEventWorld, RefreshDatabase;

    private function pages(string $pdf): int
    {
        return preg_match_all('#/Type\s*/Page[^s]#', $pdf);
    }

    public function test_a_confirmed_attendee_downloads_a_one_page_pdf_that_is_never_cached(): void
    {
        $event = $this->makeEvent(['name' => 'The Power of Data']);
        ['registration' => $registration] = $this->registerPaidInPersonAttendee($event);

        $response = $this->actingAs($registration->user)->get(route('ticket.pdf'));

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Disposition', 'attachment; filename=the-power-of-data-ticket.pdf');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->assertSame(1, $this->pages($response->getContent()));
    }

    public function test_a_long_programme_and_a_long_name_still_fit_on_one_page(): void
    {
        $event = $this->makeEvent(['venue_name' => 'The Very Long Name of a Conference Venue and Lodge', 'venue_address' => '123 A Rather Long Street Name, Some Suburb, Bloemfontein, Free State, 9301']);
        foreach (range(0, 15) as $i) {
            ProgrammeSession::factory()->create([
                'event_id' => $event->id,
                'title' => "Session {$i}: a deliberately long working title that would wrap onto a second line",
                'start_time' => $event->starts_at->copy()->addMinutes($i * 5),
                'end_time' => $event->starts_at->copy()->addMinutes($i * 5 + 5),
                'sort_order' => $i,
            ]);
        }
        $registration = $this->registerAttendee($event, 'in_person', [
            'first_name' => 'Nomvula Zanele-Thandiwe', 'last_name' => 'Ngubane-Mathebula Dlamini',
            'organisation' => str_repeat('Department of Economic Development ', 5),
        ]);
        $registration->ticket()->create(['code' => str_repeat('Ab3dE', 8), 'status' => 'confirmed', 'issued_at' => now()]);

        $pdf = app(TicketPdf::class)->render($registration->ticket()->first());

        $this->assertSame(1, $this->pages($pdf));
    }

    public function test_there_is_nothing_to_download_until_the_ticket_is_valid(): void
    {
        $event = $this->makeEvent();

        ['registration' => $pending] = $this->registerPaidInPersonAttendee($event, approved: false);
        $this->actingAs($pending->user)->get(route('ticket.pdf'))->assertNotFound();

        ['registration' => $cancelled, 'ticket' => $ticket] = $this->registerPaidInPersonAttendee($event);
        $ticket->update(['status' => 'cancelled']);
        $this->actingAs($cancelled->user)->get(route('ticket.pdf'))->assertNotFound();

        ['registration' => $withdrawn] = $this->registerPaidInPersonAttendee($event);
        $withdrawn->update(['status' => 'cancelled']);
        $this->actingAs($withdrawn->user)->get(route('ticket.pdf'))->assertNotFound();

        $online = $this->registerAttendee($event, 'online');
        $this->actingAs($online->user)->get(route('ticket.pdf'))->assertNotFound();
    }

    public function test_it_needs_a_signed_in_verified_account(): void
    {
        $this->get(route('ticket.pdf'))->assertRedirect(route('login'));

        $unverified = User::factory()->unverified()->create();
        $this->actingAs($unverified)->get(route('ticket.pdf'))->assertRedirect(route('verification.notice'));
    }

    public function test_downloads_are_rate_limited(): void
    {
        $event = $this->makeEvent();
        ['registration' => $registration] = $this->registerPaidInPersonAttendee($event);
        $this->partialMock(TicketPdf::class, fn ($mock) => $mock->shouldReceive('render')->andReturn('%PDF-1.7 stub'));

        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($registration->user)->get(route('ticket.pdf'))->assertOk();
        }

        $this->actingAs($registration->user)->get(route('ticket.pdf'))->assertStatus(429);
    }

    public function test_the_ticket_page_and_dashboard_offer_the_download(): void
    {
        $event = $this->makeEvent();
        ['registration' => $registration] = $this->registerPaidInPersonAttendee($event);

        $this->actingAs($registration->user)->get(route('ticket.show'))
            ->assertInertia(fn (Assert $page) => $page->where('confirmed', true)->where('admission', 'In person'));

        $this->actingAs($registration->user)->get(route('attendee.dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('ticket.status', 'confirmed'));
    }
}
