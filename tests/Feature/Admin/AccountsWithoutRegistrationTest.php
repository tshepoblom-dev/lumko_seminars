<?php

namespace Tests\Feature\Admin;

use App\Enums\ConsentType;
use App\Models\Partner;
use App\Models\Speaker;
use App\Models\User;
use App\Notifications\RegisterForEventReminder;
use App\Services\ConsentService;
use App\Services\EventStatsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesEventWorld;
use Tests\TestCase;

/**
 * Admin → Attendees: people with an account who aren't registered for the
 * event (they'd otherwise be invisible to staff), the reminder to register,
 * and the dashboard's marketing opt-in count.
 */
class AccountsWithoutRegistrationTest extends TestCase
{
    use CreatesEventWorld, RefreshDatabase;

    private function account(string $name, array $attributes = []): User
    {
        [$first, $last] = explode(' ', $name, 2);

        return User::factory()->create([
            'name' => $name, 'first_name' => $first, 'last_name' => $last,
            'email' => strtolower($first).'@example.com', ...$attributes,
        ]);
    }

    public function test_the_attendee_page_lists_accounts_that_are_not_registered_for_the_event(): void
    {
        $event = $this->makeEvent();
        $admin = $this->makeSuperAdmin();

        $signedUp = $this->account('Mojela Khonyane');
        $unconfirmed = $this->account('Buy Bloem', ['email_verified_at' => null]);
        $elsewhere = $this->registerAttendee($this->makeEvent(['starts_at' => now()->addMonth(), 'ends_at' => now()->addMonth()->addHours(2)]))->user;

        // Not listed: someone registered, a speaker, a partner's team
        // member, an anonymised account, and staff.
        $registered = $this->registerAttendee($event)->user;
        Speaker::factory()->create(['user_id' => $this->account('Sam Speaker')->id]);
        Partner::factory()->create()->members()->attach($this->account('Pat Partner')->id, ['role' => 'owner']);
        $this->account('Gone Person', ['email' => 'deleted-1@invalid', 'email_verified_at' => null]);
        $this->makeManagerFor($event);

        $this->actingAs($admin)->get(route('admin.attendees.index', $event))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Attendees/Index')
                ->where('total', 1)
                ->where('accountsOnly.count', 3)
                ->where('accountsOnly.can_remind', true)
                ->where('accountsOnly.rows', fn ($rows) => collect($rows)->pluck('id')->sort()->values()->all()
                    === collect([$signedUp->id, $unconfirmed->id, $elsewhere->id])->sort()->values()->all())
                ->where('accountsOnly.rows', fn ($rows) => collect($rows)->firstWhere('id', $unconfirmed->id)['email_confirmed'] === false
                    && collect($rows)->firstWhere('id', $signedUp->id)['reminded_on'] === null
                    && ! collect($rows)->pluck('id')->contains($registered->id)));
    }

    public function test_staff_remind_one_person_or_everyone_to_register(): void
    {
        Notification::fake();
        $event = $this->makeEvent();
        $admin = $this->makeSuperAdmin();
        $first = $this->account('Mojela Khonyane');
        $second = $this->account('Buy Bloem');
        $registered = $this->registerAttendee($event)->user;

        $this->actingAs($admin)->post(route('admin.attendees.accounts.remind', [$event, $first]))
            ->assertSessionHas('success', 'Reminder to register sent to mojela@example.com.');
        Notification::assertSentTo($first, RegisterForEventReminder::class);
        $this->assertDatabaseHas('sent_notifications', ['user_id' => $first->id, 'event_id' => $event->id, 'type' => 'register_reminder']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'attendees.register_reminder_sent']);

        $this->actingAs($admin)->get(route('admin.attendees.index', $event))
            ->assertInertia(fn (Assert $page) => $page
                ->where('accountsOnly.rows', fn ($rows) => collect($rows)->firstWhere('id', $first->id)['reminded_on'] === now()->format('j M Y')));

        // "Remind all" skips whoever was reminded in the last 24 hours.
        $this->actingAs($admin)->post(route('admin.attendees.accounts.remind-all', $event))
            ->assertSessionHas('success', 'Reminder to register sent to 1 person.');
        Notification::assertSentToTimes($first, RegisterForEventReminder::class, 1);
        Notification::assertSentToTimes($second, RegisterForEventReminder::class, 1);
        Notification::assertNotSentTo($registered, RegisterForEventReminder::class);

        $this->actingAs($admin)->post(route('admin.attendees.accounts.remind-all', $event))->assertSessionHas('info');

        // A day later everyone is due again, and one row per person is kept.
        $this->travel(25)->hours();
        $this->actingAs($admin)->post(route('admin.attendees.accounts.remind-all', $event))
            ->assertSessionHas('success', 'Reminder to register sent to 2 people.');
        $this->assertSame(2, DB::table('sent_notifications')->where('type', 'register_reminder')->count());

        // Someone who is registered can't be "reminded".
        $this->actingAs($admin)->post(route('admin.attendees.accounts.remind', [$event, $registered]))->assertNotFound();
    }

    public function test_reminders_are_for_the_events_own_staff_and_only_while_it_is_open(): void
    {
        Notification::fake();
        $event = $this->makeEvent();
        $later = $this->makeEvent(['starts_at' => now()->addMonth(), 'ends_at' => now()->addMonth()->addHours(2)]);
        $person = $this->account('Mojela Khonyane');

        $this->actingAs($this->makeManagerFor($later))
            ->post(route('admin.attendees.accounts.remind', [$event, $person]))->assertForbidden();
        $this->actingAs(User::factory()->create())
            ->post(route('admin.attendees.accounts.remind-all', $event))->assertForbidden();

        // /attend serves the earlier event, so a reminder for the later one would mislead.
        $admin = $this->makeSuperAdmin();
        $this->actingAs($admin)->get(route('admin.attendees.index', $later))
            ->assertInertia(fn (Assert $page) => $page->where('accountsOnly.can_remind', false));
        $this->actingAs($admin)->post(route('admin.attendees.accounts.remind-all', $later))->assertStatus(422);

        Notification::assertNothingSent();
    }

    public function test_the_reminder_points_at_the_registration_form(): void
    {
        $event = $this->makeEvent(['name' => 'The Power of Data']);
        $person = $this->account('Mojela Khonyane');

        $mail = (new RegisterForEventReminder($event))->toMail($person);

        $this->assertSame("You're not registered yet: The Power of Data", $mail->subject);
        $this->assertSame('Hi Mojela,', $mail->greeting);
        $this->assertSame(route('event.register'), $mail->actionUrl);
    }

    public function test_the_dashboard_counts_marketing_opt_ins_from_the_consent_ledger(): void
    {
        $event = $this->makeEvent();
        $consents = app(ConsentService::class);

        $optedIn = $this->registerAttendee($event)->user;
        $pending = $this->registerAttendee($event)->user;
        $withdrew = $this->registerAttendee($event)->user;
        $this->registerAttendee($event);
        $notHere = $this->account('Mojela Khonyane');

        $consents->grant($optedIn, ConsentType::LumkoMarketing, null, source: 'registration', effectiveImmediately: true);
        $consents->grant($pending, ConsentType::LumkoMarketing, null, source: 'registration');
        $consents->grant($withdrew, ConsentType::LumkoMarketing, null, source: 'registration', effectiveImmediately: true);
        $this->travel(1)->minutes();
        $consents->withdraw($withdrew, ConsentType::LumkoMarketing, null, source: 'account');
        $consents->grant($notHere, ConsentType::LumkoMarketing, null, source: 'account', effectiveImmediately: true);

        $this->assertSame(1, app(EventStatsService::class)->summary($event)['lumko_marketing_opt_ins']);
    }
}
