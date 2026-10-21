<?php

namespace Tests\Feature\Admin;

use App\Enums\ConsentType;
use App\Enums\UserRole;
use App\Jobs\SendCommunicationEmail;
use App\Models\Communication;
use App\Models\CommunicationRecipient;
use App\Models\User;
use App\Notifications\CommunicationNotification;
use App\Services\ConsentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesEventWorld;
use Tests\TestCase;

class CommunicationTest extends TestCase
{
    use CreatesEventWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function message(array $overrides = []): array
    {
        return [
            'category' => 'event',
            'recipient_mode' => 'all',
            'subject' => 'Parking at the venue',
            'body' => "Hi all,\n\nParking opens at 07:30.",
            ...$overrides,
        ];
    }

    private function grantMarketing(User $user): void
    {
        app(ConsentService::class)->grant($user, ConsentType::LumkoMarketing, null, 'test', effectiveImmediately: true);
    }

    public function test_sending_to_everyone_reaches_only_registered_attendees_of_the_event(): void
    {
        Notification::fake();
        $event = $this->makeEvent();
        $admin = $this->makeSuperAdmin();
        $a = $this->registerAttendee($event)->user;
        $b = $this->registerAttendee($event, 'in_person')->user;
        $cancelled = $this->registerAttendee($event)->user;
        $cancelled->registrations()->update(['status' => 'cancelled']);
        $otherEvent = $this->registerAttendee($this->makeEvent())->user;

        $this->actingAs($admin)
            ->post(route('admin.communications.store', $event), $this->message())
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        Notification::assertSentTo([$a, $b], CommunicationNotification::class);
        Notification::assertNotSentTo([$cancelled, $otherEvent, $admin], CommunicationNotification::class);

        $communication = Communication::sole();
        $this->assertSame(2, $communication->recipient_count);
        $this->assertSame(2, $communication->sent_count);
        $this->assertSame('sent', $communication->status);
        $this->assertSame(2, CommunicationRecipient::where('status', 'sent')->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'communication.sent', 'subject_id' => $communication->id]);
    }

    public function test_filtered_send_uses_the_filters(): void
    {
        Notification::fake();
        $event = $this->makeEvent();
        $govOnline = $this->registerAttendee($event);
        $govOnline->update(['industry' => 'Government/Municipal']);
        $govInPerson = $this->registerAttendee($event, 'in_person');
        $govInPerson->update(['industry' => 'Government/Municipal']);
        $retail = $this->registerAttendee($event);
        $retail->update(['industry' => 'Retail']);

        $this->actingAs($this->makeSuperAdmin())->post(route('admin.communications.store', $event), $this->message([
            'recipient_mode' => 'filtered',
            'filters' => ['industry' => 'Government/Municipal', 'mode' => 'online'],
        ]))->assertSessionHasNoErrors();

        Notification::assertSentTo($govOnline->user, CommunicationNotification::class);
        Notification::assertNotSentTo([$govInPerson->user, $retail->user], CommunicationNotification::class);
        $this->assertEquals(['industry' => 'Government/Municipal', 'mode' => 'online'], Communication::sole()->filters);
    }

    public function test_selected_send_reaches_only_the_chosen_people_within_the_event(): void
    {
        Notification::fake();
        $event = $this->makeEvent();
        $chosen = $this->registerAttendee($event)->user;
        $notChosen = $this->registerAttendee($event)->user;
        $outsider = $this->registerAttendee($this->makeEvent())->user;

        $this->actingAs($this->makeSuperAdmin())->post(route('admin.communications.store', $event), $this->message([
            'recipient_mode' => 'selected',
            'user_ids' => [$chosen->id, $outsider->id],
        ]))->assertSessionHasNoErrors();

        Notification::assertSentTo($chosen, CommunicationNotification::class);
        Notification::assertNotSentTo([$notChosen, $outsider], CommunicationNotification::class);
        $this->assertSame(1, Communication::sole()->recipient_count);
    }

    public function test_selected_mode_requires_at_least_one_person(): void
    {
        $event = $this->makeEvent();
        $this->registerAttendee($event);

        $this->actingAs($this->makeSuperAdmin())
            ->post(route('admin.communications.store', $event), $this->message(['recipient_mode' => 'selected']))
            ->assertSessionHasErrors('user_ids');

        $this->assertSame(0, Communication::count());
    }

    public function test_marketing_only_reaches_people_with_lumko_marketing_consent(): void
    {
        Notification::fake();
        $event = $this->makeEvent();
        $consented = $this->registerAttendee($event)->user;
        $this->grantMarketing($consented);
        $withdrawn = $this->registerAttendee($event)->user;
        $this->grantMarketing($withdrawn);
        app(ConsentService::class)->withdraw($withdrawn, ConsentType::LumkoMarketing, null, 'test');
        $never = $this->registerAttendee($event)->user;

        $this->actingAs($this->makeSuperAdmin())
            ->post(route('admin.communications.store', $event), $this->message(['category' => 'marketing']))
            ->assertSessionHasNoErrors();

        Notification::assertSentTo($consented, CommunicationNotification::class);
        Notification::assertNotSentTo([$withdrawn, $never], CommunicationNotification::class);
    }

    public function test_marketing_consent_withdrawn_after_queueing_is_skipped_at_send_time(): void
    {
        Queue::fake();
        Notification::fake();
        $event = $this->makeEvent();
        $user = $this->registerAttendee($event)->user;
        $this->grantMarketing($user);

        $this->actingAs($this->makeSuperAdmin())
            ->post(route('admin.communications.store', $event), $this->message(['category' => 'marketing']))
            ->assertSessionHasNoErrors();

        app(ConsentService::class)->withdraw($user, ConsentType::LumkoMarketing, null, 'test');

        Queue::assertPushed(SendCommunicationEmail::class, function (SendCommunicationEmail $job) {
            $job->handle(app(ConsentService::class));

            return true;
        });

        Notification::assertNothingSent();
        $this->assertSame('skipped_no_consent', CommunicationRecipient::sole()->status);
        $communication = Communication::sole();
        $this->assertSame(1, $communication->skipped_count);
        $this->assertSame('sent', $communication->status);
    }

    public function test_marketing_send_with_no_consenting_recipients_is_refused(): void
    {
        Notification::fake();
        $event = $this->makeEvent();
        $this->registerAttendee($event);

        $this->actingAs($this->makeSuperAdmin())
            ->post(route('admin.communications.store', $event), $this->message(['category' => 'marketing']))
            ->assertSessionHasErrors('recipient_mode');

        $this->assertSame(0, Communication::count());
        Notification::assertNothingSent();
    }

    public function test_attachments_are_stored_privately_and_attached(): void
    {
        Notification::fake();
        $event = $this->makeEvent();
        $user = $this->registerAttendee($event)->user;

        $this->actingAs($this->makeSuperAdmin())->post(route('admin.communications.store', $event), $this->message([
            'attachments' => [UploadedFile::fake()->create('programme.pdf', 200, 'application/pdf')],
        ]))->assertSessionHasNoErrors();

        $attachment = Communication::sole()->attachments()->sole();
        $this->assertSame('programme.pdf', $attachment->original_name);
        Storage::disk('local')->assertExists($attachment->path);

        Notification::assertSentTo($user, CommunicationNotification::class, function (CommunicationNotification $n) use ($user) {
            $mail = $n->toMail($user);

            return count($mail->attachments) === 1 && $mail->attachments[0]['options']['as'] === 'programme.pdf';
        });
    }

    public function test_attachments_over_the_total_limit_are_rejected(): void
    {
        $event = $this->makeEvent();
        $this->registerAttendee($event);

        $this->actingAs($this->makeSuperAdmin())->post(route('admin.communications.store', $event), $this->message([
            'attachments' => [
                UploadedFile::fake()->create('a.pdf', 6000, 'application/pdf'),
                UploadedFile::fake()->create('b.pdf', 6000, 'application/pdf'),
            ],
        ]))->assertSessionHasErrors('attachments');

        $this->assertSame(0, Communication::count());
    }

    public function test_disallowed_attachment_types_are_rejected(): void
    {
        $event = $this->makeEvent();
        $this->registerAttendee($event);

        $this->actingAs($this->makeSuperAdmin())->post(route('admin.communications.store', $event), $this->message([
            'attachments' => [UploadedFile::fake()->create('page.html', 5, 'text/html')],
        ]))->assertSessionHasErrors('attachments.0');
    }

    public function test_mail_personalises_the_greeting_and_body_and_carries_the_footer(): void
    {
        $user = User::factory()->create(['first_name' => 'Thandi']);
        $notification = new CommunicationNotification('Hello', "Dear {first_name},\n\nSee you there.", \App\Enums\CommunicationCategory::Event);

        $mail = $notification->toMail($user);

        $this->assertSame('Hi Thandi,', $mail->greeting);
        $this->assertSame('Dear Thandi,', $mail->introLines[0]);
        $this->assertSame('See you there.', $mail->introLines[1]);
        $this->assertStringContainsString(route('attendee.preferences.edit'), implode("\n", $mail->introLines));
    }

    public function test_test_send_goes_only_to_the_entered_address(): void
    {
        Notification::fake();
        $event = $this->makeEvent();
        $attendee = $this->registerAttendee($event)->user;
        $admin = $this->makeSuperAdmin();

        $this->actingAs($admin)->post(route('admin.communications.test', $event), $this->message([
            'test_email' => 'owner@example.com',
        ]))->assertRedirect()->assertSessionHasNoErrors();

        Notification::assertSentOnDemand(CommunicationNotification::class, function ($notification, $channels, AnonymousNotifiable $notifiable) {
            return $notifiable->routes['mail'] === 'owner@example.com'
                && $notification->subject === '[TEST] Parking at the venue';
        });
        Notification::assertNotSentTo([$attendee, $admin], CommunicationNotification::class);
        $this->assertSame(0, Communication::count());
        $this->assertSame(0, CommunicationRecipient::count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'communication.test_sent']);
    }

    public function test_test_send_rejects_an_invalid_address(): void
    {
        Notification::fake();
        $event = $this->makeEvent();

        $this->actingAs($this->makeSuperAdmin())
            ->post(route('admin.communications.test', $event), $this->message(['test_email' => 'not an email']))
            ->assertSessionHasErrors('test_email');

        Notification::assertNothingSent();
    }

    public function test_manager_can_send_for_their_event_but_not_another(): void
    {
        Notification::fake();
        $this->withoutMiddleware(ThrottleRequests::class);
        $mine = $this->makeEvent();
        $theirs = $this->makeEvent();
        $manager = $this->makeManagerFor($mine);
        $this->registerAttendee($mine);
        $this->registerAttendee($theirs);

        $this->actingAs($manager)->get(route('admin.communications.create', $mine))->assertOk();
        $this->actingAs($manager)->post(route('admin.communications.store', $mine), $this->message())->assertSessionHasNoErrors();

        $this->actingAs($manager)->get(route('admin.communications.index', $theirs))->assertForbidden();
        $this->actingAs($manager)->get(route('admin.communications.create', $theirs))->assertForbidden();
        $this->actingAs($manager)->post(route('admin.communications.store', $theirs), $this->message())->assertForbidden();
        $this->actingAs($manager)->post(route('admin.communications.test', $theirs), $this->message(['test_email' => 'x@example.com']))->assertForbidden();

        $this->assertSame(1, Communication::count());
    }

    public function test_a_communication_is_only_visible_through_its_own_event(): void
    {
        Notification::fake();
        $event = $this->makeEvent();
        $other = $this->makeEvent();
        $admin = $this->makeSuperAdmin();
        $this->registerAttendee($event);
        $this->actingAs($admin)->post(route('admin.communications.store', $event), $this->message());
        $communication = Communication::sole();

        $this->actingAs($admin)->get(route('admin.communications.show', [$event, $communication]))->assertOk();
        $this->actingAs($admin)->get(route('admin.communications.show', [$other, $communication]))->assertNotFound();
    }

    public function test_platform_send_is_super_admin_only_and_always_marketing(): void
    {
        Notification::fake();
        $event = $this->makeEvent();
        $manager = $this->makeManagerFor($event);
        $consented = $this->registerAttendee($event)->user;
        $this->grantMarketing($consented);
        $unregisteredButConsented = User::factory()->create(['role' => UserRole::Attendee]);
        $this->grantMarketing($unregisteredButConsented);
        $noConsent = $this->registerAttendee($event)->user;
        $staff = $this->makeSuperAdmin();
        $this->grantMarketing($staff);

        $this->actingAs($manager)->get(route('admin.platform-communications.index'))->assertForbidden();
        $this->actingAs($manager)->post(route('admin.platform-communications.store'), $this->message())->assertForbidden();

        // Sent as an "event update", but a platform send is always marketing.
        $this->actingAs($staff)->post(route('admin.platform-communications.store'), $this->message())->assertSessionHasNoErrors();

        Notification::assertSentTo([$consented, $unregisteredButConsented], CommunicationNotification::class);
        Notification::assertNotSentTo([$noConsent, $staff, $manager], CommunicationNotification::class);
        $communication = Communication::sole();
        $this->assertNull($communication->event_id);
        $this->assertSame('marketing', $communication->category->value);

        $this->actingAs($staff)->get(route('admin.platform-communications.show', $communication))->assertOk();
    }

    public function test_platform_filter_by_event_and_industry(): void
    {
        Notification::fake();
        $eventA = $this->makeEvent();
        $eventB = $this->makeEvent();
        $inA = $this->registerAttendee($eventA);
        $inA->update(['industry' => 'Education']);
        $inB = $this->registerAttendee($eventB);
        $inB->update(['industry' => 'Education']);
        $this->grantMarketing($inA->user);
        $this->grantMarketing($inB->user);

        $this->actingAs($this->makeSuperAdmin())->post(route('admin.platform-communications.store'), $this->message([
            'recipient_mode' => 'filtered',
            'filters' => ['event_id' => (string) $eventA->id, 'industry' => 'Education'],
        ]))->assertSessionHasNoErrors();

        Notification::assertSentTo($inA->user, CommunicationNotification::class);
        Notification::assertNotSentTo($inB->user, CommunicationNotification::class);
    }

    public function test_composer_counts_the_audience_and_lists_candidates(): void
    {
        $event = $this->makeEvent();
        $admin = $this->makeSuperAdmin();
        $retail = $this->registerAttendee($event, 'online', ['name' => 'Retail Person']);
        $retail->update(['industry' => 'Retail']);
        $this->registerAttendee($event);
        $this->registerAttendee($event);
        $this->grantMarketing($retail->user);

        $this->actingAs($admin)->get(route('admin.communications.create', $event))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Communications/Compose')
                ->where('audience.count', 3)
                ->where('candidates', null));

        $this->actingAs($admin)->get(route('admin.communications.create', [$event, 'mode' => 'filtered', 'filters' => ['industry' => 'Retail']]))
            ->assertInertia(fn (Assert $page) => $page->where('audience.count', 1));

        $this->actingAs($admin)->get(route('admin.communications.create', [$event, 'category' => 'marketing']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('audience.count', 1)
                ->where('audience.without_consent', 2));

        $this->actingAs($admin)->get(route('admin.communications.create', [$event, 'mode' => 'selected', 'user_ids' => [$retail->user_id]]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('candidates.data', 3)
                ->has('preselected', 1)
                ->where('preselected.0.name', 'Retail Person'));
    }

    public function test_attachment_download_is_scoped_to_its_communication(): void
    {
        Notification::fake();
        $event = $this->makeEvent();
        $admin = $this->makeSuperAdmin();
        $this->registerAttendee($event);
        $this->actingAs($admin)->post(route('admin.communications.store', $event), $this->message([
            'attachments' => [UploadedFile::fake()->create('agenda.pdf', 10, 'application/pdf')],
        ]));
        $communication = Communication::sole();
        $attachment = $communication->attachments()->sole();

        $this->actingAs($admin)
            ->get(route('admin.communications.attachment', [$event, $communication, $attachment]))
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->actingAs($this->makeManagerFor($this->makeEvent()))
            ->get(route('admin.communications.attachment', [$event, $communication, $attachment]))
            ->assertForbidden();
    }

    public function test_a_failed_delivery_is_recorded_on_the_recipient(): void
    {
        Queue::fake();
        $event = $this->makeEvent();
        $this->registerAttendee($event);
        $this->actingAs($this->makeSuperAdmin())->post(route('admin.communications.store', $event), $this->message());

        (new SendCommunicationEmail(CommunicationRecipient::sole()->id))->failed(new \RuntimeException('550 mailbox unavailable'));

        $recipient = CommunicationRecipient::sole();
        $this->assertSame('failed', $recipient->status);
        $this->assertSame('550 mailbox unavailable', $recipient->error);
        $communication = Communication::sole();
        $this->assertSame(1, $communication->failed_count);
        $this->assertSame('failed', $communication->status);
    }

    public function test_jobs_are_spread_out_at_the_configured_rate(): void
    {
        Queue::fake();
        config(['seminar.communications.per_minute' => 2]);
        $event = $this->makeEvent();
        foreach (range(1, 5) as $i) {
            $this->registerAttendee($event);
        }

        $this->actingAs($this->makeSuperAdmin())->post(route('admin.communications.store', $event), $this->message());

        $delays = [];
        Queue::assertPushed(SendCommunicationEmail::class, function ($job) use (&$delays) {
            $delays[] = (int) round(now()->diffInMinutes($job->delay, true));

            return true;
        });
        sort($delays);
        $this->assertSame([0, 0, 1, 1, 2], $delays);
    }
}
