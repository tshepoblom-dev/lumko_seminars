<?php

namespace Tests\Feature\Public;

use App\Enums\UserRole;
use App\Models\Event;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\PaymentApproved;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationBellTest extends TestCase
{
    use RefreshDatabase;

    private function paymentFor(User $user): Payment
    {
        $event = Event::factory()->create();
        $registration = $user->registrations()->create(['event_id' => $event->id, 'attendance_mode' => 'in_person', 'status' => 'registered']);

        return $registration->payment()->create(['amount' => 150, 'status' => 'approved']);
    }

    public function test_unread_count_and_mark_read(): void
    {
        $user = User::factory()->create(['role' => UserRole::Attendee]);
        $user->notify(new PaymentApproved($this->paymentFor($user)));

        $response = $this->actingAs($user)->get(route('attendee.dashboard'));
        $response->assertInertia(fn ($page) => $page->where('notifications.unreadCount', 1));

        $notificationId = $user->notifications()->first()->id;
        $this->actingAs($user)->post(route('notifications.read', $notificationId))->assertRedirect();

        $this->assertNotNull($user->notifications()->first()->read_at);
    }

    public function test_mark_all_read(): void
    {
        $user = User::factory()->create(['role' => UserRole::Attendee]);
        $payment = $this->paymentFor($user);
        $user->notify(new PaymentApproved($payment));
        $user->notify(new PaymentApproved($payment));

        $this->actingAs($user)->post(route('notifications.read-all'))->assertRedirect();

        $this->assertSame(0, $user->unreadNotifications()->count());
    }
}
