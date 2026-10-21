<?php

namespace Tests\Feature\Public;

use App\Enums\PaymentStatus;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Bank details come from config/.env, never from code. */
class PaymentDetailsConfigTest extends TestCase
{
    use RefreshDatabase;

    private function attendeeWithPayment(): User
    {
        $event = Event::factory()->create(['status' => 'published', 'in_person_price' => 150]);
        $user = User::factory()->create(['role' => UserRole::Attendee]);
        $registration = $user->registrations()->create([
            'event_id' => $event->id,
            'attendance_mode' => 'in_person',
            'status' => 'registered',
        ]);
        $registration->payment()->create(['amount' => 150, 'status' => PaymentStatus::Pending]);
        $registration->ticket()->create(['code' => 'abc', 'status' => TicketStatus::Pending]);

        return $user;
    }

    public function test_payment_page_reports_details_not_configured_when_env_is_missing(): void
    {
        config(['seminar.payment' => ['account_name' => null, 'bank' => null, 'account_number' => null, 'branch_code' => null, 'account_type' => null, 'reference_prefix' => 'EVENT-']]);

        $this->actingAs($this->attendeeWithPayment())->get(route('payment.show'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('bankDetails.configured', false)
                ->where('bankDetails.account_number', null));
    }

    public function test_payment_page_shows_full_details_when_configured(): void
    {
        config(['seminar.payment' => [
            'account_name' => 'Test Account', 'bank' => 'Test Bank', 'account_number' => '123456789',
            'branch_code' => '000000', 'account_type' => 'Current', 'reference_prefix' => 'EVENT-',
        ]]);

        $this->actingAs($this->attendeeWithPayment())->get(route('payment.show'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('bankDetails.configured', true)
                ->where('bankDetails.bank', 'Test Bank')
                ->where('bankDetails.account_number', '123456789'));
    }

    public function test_admin_dashboard_warns_when_bank_details_are_missing(): void
    {
        config(['seminar.payment.account_number' => null]);
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);

        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('warnings.payment_details_missing', true));
    }
}
