<?php

namespace Tests\Feature\Public;

use App\Enums\ConsentType;
use App\Enums\UserRole;
use App\Models\AttendeeConsent;
use App\Models\ConsentRecord;
use App\Models\Event;
use App\Models\User;
use App\Services\ConsentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PreferencesTest extends TestCase
{
    use RefreshDatabase;

    public function test_attendee_can_withdraw_networking_consent(): void
    {
        $event = Event::factory()->create(['status' => 'published']);
        $user = User::factory()->create([
            'role' => UserRole::Attendee, 'first_name' => 'A', 'last_name' => 'B',
            'mobile' => '0000', 'organisation' => 'Org', 'job_title' => 'Title',
        ]);
        $user->registrations()->create(['event_id' => $event->id, 'attendance_mode' => 'online', 'status' => 'registered']);
        AttendeeConsent::create([
            'user_id' => $user->id, 'event_id' => $event->id, 'type' => 'networking', 'granted_at' => now(),
        ]);

        $response = $this->actingAs($user)->put(route('attendee.preferences.update'), [
            'first_name' => 'A', 'last_name' => 'B', 'mobile' => '0000',
            'organisation' => 'Org', 'job_title' => 'Title',
            'networking_consent' => false, 'lumko_marketing_consent' => false,
        ]);

        $response->assertRedirect();

        $consent = AttendeeConsent::where('user_id', $user->id)->where('type', 'networking')->first();
        $this->assertNotNull($consent->revoked_at);
        $this->assertFalse($consent->isGranted());
    }

    // T-CONS-1 — grant -> withdraw -> re-grant writes 3 ledger rows; nothing overwritten.
    public function test_consent_changes_append_to_the_ledger_instead_of_overwriting(): void
    {
        $event = Event::factory()->create(['status' => 'published']);
        $user = User::factory()->create([
            'role' => UserRole::Attendee, 'first_name' => 'A', 'last_name' => 'B',
            'mobile' => '0000', 'organisation' => 'Org', 'job_title' => 'Title',
        ]);
        $user->registrations()->create(['event_id' => $event->id, 'attendance_mode' => 'online', 'status' => 'registered']);

        $payload = fn (bool $networking) => [
            'first_name' => 'A', 'last_name' => 'B', 'mobile' => '0000',
            'organisation' => 'Org', 'job_title' => 'Title',
            'networking_consent' => $networking, 'lumko_marketing_consent' => false,
        ];

        $this->actingAs($user)->put(route('attendee.preferences.update'), $payload(true)); // grant
        $this->actingAs($user)->put(route('attendee.preferences.update'), $payload(false)); // withdraw
        $this->actingAs($user)->put(route('attendee.preferences.update'), $payload(true)); // re-grant

        $this->assertSame(3, ConsentRecord::where('user_id', $user->id)->where('type', 'networking')->count());
        $this->assertTrue(app(ConsentService::class)->isGranted($user, ConsentType::Networking, $event));
    }

    // T-CONS-2 — lumko_marketing is global: withdrawing at /account affects every event.
    public function test_marketing_consent_withdrawal_is_global(): void
    {
        $eventA = Event::factory()->create(['status' => 'published']);
        $user = User::factory()->create([
            'role' => UserRole::Attendee, 'first_name' => 'A', 'last_name' => 'B',
            'mobile' => '0000', 'organisation' => 'Org', 'job_title' => 'Title',
        ]);
        $user->registrations()->create(['event_id' => $eventA->id, 'attendance_mode' => 'online', 'status' => 'registered']);

        app(ConsentService::class)->grant($user, ConsentType::LumkoMarketing, null, source: 'registration', effectiveImmediately: true);
        $this->assertTrue(app(ConsentService::class)->isGranted($user, ConsentType::LumkoMarketing));

        $this->actingAs($user)->put(route('attendee.preferences.update'), [
            'first_name' => 'A', 'last_name' => 'B', 'mobile' => '0000',
            'organisation' => 'Org', 'job_title' => 'Title',
            'networking_consent' => false, 'lumko_marketing_consent' => false,
        ]);

        $this->assertFalse(app(ConsentService::class)->isGranted($user, ConsentType::LumkoMarketing));
    }

    public function test_mobile_is_optional_and_can_be_cleared(): void
    {
        $user = User::factory()->create([
            'role' => UserRole::Attendee, 'first_name' => 'A', 'last_name' => 'B',
            'mobile' => '0821234567', 'organisation' => 'Org', 'job_title' => 'Title',
        ]);

        $this->actingAs($user)->put(route('attendee.preferences.update'), [
            'first_name' => 'A', 'last_name' => 'B', 'mobile' => '',
            'organisation' => 'Org', 'job_title' => 'Title',
            'networking_consent' => false, 'lumko_marketing_consent' => false,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertNull($user->fresh()->mobile);
    }

    public function test_download_my_data_includes_registration_and_consent_history(): void
    {
        $event = Event::factory()->create(['status' => 'published']);
        $user = User::factory()->create(['role' => UserRole::Attendee, 'name' => 'Data Export Test']);
        $user->registrations()->create(['event_id' => $event->id, 'attendance_mode' => 'online', 'status' => 'registered']);
        ConsentRecord::create([
            'user_id' => $user->id, 'event_id' => $event->id, 'type' => 'networking', 'action' => 'granted',
            'purpose_key' => 'networking', 'purpose_text' => 'Networking consent', 'purpose_version' => 'v1',
            'source' => 'registration', 'effective_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('attendee.preferences.download'));

        $response->assertOk();
        $response->assertHeader('content-disposition');
        $json = $response->json();

        $this->assertSame('Data Export Test', $json['profile']['name']);
        $this->assertCount(1, $json['registrations']);
        $this->assertSame($event->name, $json['registrations'][0]['event']);
        $this->assertCount(1, $json['consent_history']);
        $this->assertSame('networking', $json['consent_history'][0]['type']);
    }

    public function test_download_my_data_never_exposes_another_users_data(): void
    {
        $event = Event::factory()->create(['status' => 'published']);
        $user = User::factory()->create(['role' => UserRole::Attendee]);
        $other = User::factory()->create(['role' => UserRole::Attendee, 'name' => 'Someone Else']);
        $other->registrations()->create(['event_id' => $event->id, 'attendance_mode' => 'online', 'status' => 'registered']);

        $response = $this->actingAs($user)->get(route('attendee.preferences.download'));

        $response->assertOk();
        $this->assertStringNotContainsString('Someone Else', $response->getContent());
    }
}
