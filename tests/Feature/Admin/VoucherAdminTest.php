<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\Voucher;
use App\Notifications\VoucherInvitationNotification;
use App\Services\EventStatsService;
use App\Services\VoucherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\Concerns\CreatesEventWorld;
use Tests\TestCase;

/** Event → Vouchers: issue, email, revoke honorary invitation codes. */
class VoucherAdminTest extends TestCase
{
    use CreatesEventWorld, RefreshDatabase;

    public function test_the_list_shows_codes_their_status_and_who_redeemed_them(): void
    {
        $event = $this->makeEvent();
        $admin = $this->makeSuperAdmin();
        $unused = Voucher::factory()->create(['event_id' => $event->id, 'recipient_name' => 'Unused Guest']);
        $redeemed = Voucher::factory()->create(['event_id' => $event->id, 'recipient_name' => 'Redeemed Guest']);
        $registration = $this->registerAttendee($event, 'online', ['first_name' => 'Naledi', 'last_name' => 'Dlamini', 'email_verified_at' => null]);
        app(VoucherService::class)->redeem($redeemed, $registration, $registration->user);
        Voucher::factory()->revoked()->create(['event_id' => $event->id]);
        Voucher::factory()->create(); // another event's

        $this->actingAs($admin)->get(route('admin.vouchers.index', $event))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Vouchers/Index')
                ->where('registrationOpen', true)
                ->where('summary', ['issued' => 3, 'redeemed' => 1, 'outstanding' => 1, 'revoked' => 1])
                ->has('vouchers', 3)
                ->where('vouchers', fn ($rows) => collect($rows)->contains(fn ($row) => $row['recipient_name'] === 'Unused Guest'
                    && $row['code'] === $unused->code
                    && $row['status'] === 'unused'
                    && $row['can_revoke'] === true))
                ->where('vouchers', fn ($rows) => collect($rows)->contains(fn ($row) => $row['recipient_name'] === 'Redeemed Guest'
                    && $row['status'] === 'redeemed'
                    && $row['can_revoke'] === false
                    && $row['redeemed_by']['name'] === 'Naledi Dlamini'
                    && $row['redeemed_by']['account_ready'] === false)));
    }

    public function test_an_assigned_manager_can_issue_but_a_manager_of_another_event_cannot_touch_anything(): void
    {
        Notification::fake();
        $event = $this->makeEvent();
        $voucher = Voucher::factory()->create(['event_id' => $event->id, 'recipient_email' => 'guest@example.com']);
        $manager = $this->makeManagerFor($event);
        $outsider = $this->makeManagerFor($this->makeEvent());

        $this->actingAs($manager)->get(route('admin.vouchers.index', $event))->assertOk();
        $this->actingAs($manager)->post(route('admin.vouchers.store', $event), ['recipient_name' => 'By Manager'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('vouchers', ['recipient_name' => 'By Manager', 'created_by' => $manager->id]);

        $this->actingAs($outsider)->get(route('admin.vouchers.index', $event))->assertForbidden();
        $this->actingAs($outsider)->post(route('admin.vouchers.store', $event), ['recipient_name' => 'Nope'])->assertForbidden();
        $this->actingAs($outsider)->post(route('admin.vouchers.send', [$event, $voucher]))->assertForbidden();
        $this->actingAs($outsider)->post(route('admin.vouchers.revoke', [$event, $voucher]))->assertForbidden();

        $this->assertDatabaseMissing('vouchers', ['recipient_name' => 'Nope']);
        $this->assertNull($voucher->fresh()->revoked_at);
        Notification::assertNothingSent();
    }

    public function test_attendees_cannot_reach_the_page(): void
    {
        $event = $this->makeEvent();
        $attendee = User::factory()->create(['role' => UserRole::Attendee]);

        $this->actingAs($attendee)->get(route('admin.vouchers.index', $event))->assertForbidden();
    }

    public function test_a_voucher_from_another_event_is_not_found_in_this_workspace(): void
    {
        $event = $this->makeEvent();
        $foreign = Voucher::factory()->create(['event_id' => $this->makeEvent()->id]);

        $this->actingAs($this->makeSuperAdmin())
            ->post(route('admin.vouchers.revoke', [$event, $foreign]))
            ->assertNotFound();

        $this->assertNull($foreign->fresh()->revoked_at);
    }

    public function test_issuing_a_code_without_emailing_it_is_audited_without_the_code(): void
    {
        Notification::fake();
        $event = $this->makeEvent();
        $admin = $this->makeSuperAdmin();

        $this->actingAs($admin)->post(route('admin.vouchers.store', $event), [
            'recipient_name' => 'Dr Zanele Khumalo',
            'recipient_email' => ' Zanele@Example.com ',
            'email_locked' => true,
            'send_now' => false,
            'note' => 'Keynote guest',
        ])->assertRedirect()->assertSessionHas('success');

        $voucher = Voucher::firstOrFail();
        $this->assertSame('zanele@example.com', $voucher->recipient_email);
        $this->assertTrue($voucher->email_locked);
        $this->assertSame($admin->id, $voucher->created_by);
        $this->assertMatchesRegularExpression('/^[0-9A-Z]{4}-[0-9A-Z]{4}-[0-9A-Z]{4}$/', $voucher->code);
        $this->assertNull($voucher->sent_at);
        Notification::assertNothingSent();

        $log = AuditLog::where('action', 'voucher.created')->firstOrFail();
        $this->assertSame($voucher->id, $log->subject_id);
        $this->assertStringNotContainsString($voucher->code, json_encode([$log->before, $log->after]));
    }

    public function test_issuing_and_emailing_sends_the_code_and_a_link_that_fills_it_in(): void
    {
        Notification::fake();
        $event = $this->makeEvent(['name' => 'The Power of Data', 'venue_name' => 'Lumko House']);

        $this->actingAs($this->makeSuperAdmin())->post(route('admin.vouchers.store', $event), [
            'recipient_name' => 'Zanele Khumalo',
            'recipient_email' => 'zanele@example.com',
            'email_locked' => true,
            'send_now' => true,
        ])->assertSessionHas('success');

        $voucher = Voucher::firstOrFail();
        $this->assertNotNull($voucher->sent_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'voucher.sent', 'subject_id' => $voucher->id]);

        Notification::assertSentOnDemand(VoucherInvitationNotification::class, function ($n, $channels, AnonymousNotifiable $notifiable) use ($voucher) {
            $mail = $n->toMail($notifiable);
            $text = implode("\n", [...$mail->introLines, ...$mail->outroLines]);

            return $notifiable->routes['mail'] === 'zanele@example.com'
                && $mail->actionUrl === route('event.register', ['code' => $voucher->code])
                && str_contains($text, $voucher->code)
                && str_contains($text, 'Lumko House')
                && str_contains($text, 'please register with zanele@example.com')
                && str_contains($text, route('voucher.redeem', ['code' => $voucher->code]))
                && str_starts_with($mail->greeting, 'Dear Zanele');
        });
    }

    public function test_a_failed_email_keeps_the_code_unsent_so_it_can_be_copied_or_retried(): void
    {
        $event = $this->makeEvent();
        $this->partialMock(VoucherService::class, fn ($mock) => $mock
            ->shouldReceive('send')->andThrow(new RuntimeException('SMTP down')));

        $this->actingAs($this->makeSuperAdmin())->post(route('admin.vouchers.store', $event), [
            'recipient_name' => 'Zanele Khumalo',
            'recipient_email' => 'zanele@example.com',
            'send_now' => true,
        ])->assertSessionHas('error', fn ($m) => str_contains($m, 'created') && str_contains($m, 'could not be sent'));

        $voucher = Voucher::firstOrFail();
        $this->assertNull($voucher->sent_at);
        $this->assertNull($voucher->revoked_at);
    }

    public function test_an_email_address_is_needed_to_reserve_or_email_a_code(): void
    {
        $event = $this->makeEvent();
        $admin = $this->makeSuperAdmin();

        $this->actingAs($admin)->post(route('admin.vouchers.store', $event), ['recipient_name' => 'A', 'email_locked' => true])
            ->assertSessionHasErrors('recipient_email');
        $this->actingAs($admin)->post(route('admin.vouchers.store', $event), ['recipient_name' => 'B', 'send_now' => true])
            ->assertSessionHasErrors('recipient_email');
        $this->actingAs($admin)->post(route('admin.vouchers.store', $event), ['recipient_email' => 'c@example.com'])
            ->assertSessionHasErrors('recipient_name');

        $this->assertSame(0, Voucher::count());
    }

    public function test_a_code_for_a_draft_event_is_created_but_not_emailed_yet(): void
    {
        Notification::fake();
        $event = $this->makeEvent(['status' => 'draft']);

        $this->actingAs($this->makeSuperAdmin())->post(route('admin.vouchers.store', $event), [
            'recipient_name' => 'Early Guest',
            'recipient_email' => 'early@example.com',
            'send_now' => true,
        ])->assertSessionHas('info');

        $this->assertNull(Voucher::firstOrFail()->sent_at);
        Notification::assertNothingSent();
    }

    public function test_resending_and_what_cannot_be_sent(): void
    {
        Notification::fake();
        $event = $this->makeEvent();
        $admin = $this->makeSuperAdmin();
        $withEmail = Voucher::factory()->create(['event_id' => $event->id, 'recipient_email' => 'guest@example.com']);
        $withoutEmail = Voucher::factory()->create(['event_id' => $event->id]);
        $revoked = Voucher::factory()->revoked()->create(['event_id' => $event->id, 'recipient_email' => 'gone@example.com']);

        $this->actingAs($admin)->post(route('admin.vouchers.send', [$event, $withEmail]))->assertSessionHas('success');
        $this->actingAs($admin)->post(route('admin.vouchers.send', [$event, $withoutEmail]))->assertSessionHas('error');
        $this->actingAs($admin)->post(route('admin.vouchers.send', [$event, $revoked]))->assertSessionHas('error');

        $this->assertNotNull($withEmail->fresh()->sent_at);
        Notification::assertSentOnDemandTimes(VoucherInvitationNotification::class, 1);
    }

    public function test_revoking_stops_an_unused_code_but_not_a_redeemed_one(): void
    {
        $event = $this->makeEvent();
        $admin = $this->makeSuperAdmin();
        $unused = Voucher::factory()->create(['event_id' => $event->id]);
        $redeemed = Voucher::factory()->create(['event_id' => $event->id]);
        $registration = $this->registerAttendee($event);
        app(VoucherService::class)->redeem($redeemed, $registration, $registration->user);

        $this->actingAs($admin)->post(route('admin.vouchers.revoke', [$event, $unused]))->assertSessionHas('success');
        $this->actingAs($admin)->post(route('admin.vouchers.revoke', [$event, $redeemed]))
            ->assertSessionHas('error', 'This code has already been redeemed, so it can no longer be revoked.');

        $this->assertNotNull($unused->fresh()->revoked_at);
        $this->assertNull($redeemed->fresh()->revoked_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'voucher.revoked', 'subject_id' => $unused->id, 'actor_id' => $admin->id]);
        $this->assertSame(1, AuditLog::where('action', 'voucher.revoked')->count());
    }

    public function test_complimentary_seats_are_counted_apart_from_paid_ones(): void
    {
        $event = $this->makeEvent();
        $this->registerPaidInPersonAttendee($event);
        $this->registerPaidInPersonAttendee($event, approved: false);
        $registration = $this->registerAttendee($event);
        app(VoucherService::class)->redeem(Voucher::factory()->create(['event_id' => $event->id]), $registration, $registration->user);

        $totals = app(EventStatsService::class)->dashboardExtras($event)['payment_totals'];

        $this->assertSame(1, $totals['approved']);
        $this->assertSame(1, $totals['complimentary']);
        $this->assertSame(1, $totals['pending']);
        $this->assertSame(150.0, $totals['verified_amount']);

        $this->actingAs($this->makeSuperAdmin())->get(route('admin.events.show', $event))
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.payments_approved', 1)
                ->where('stats.payments_complimentary', 1));
    }
}
