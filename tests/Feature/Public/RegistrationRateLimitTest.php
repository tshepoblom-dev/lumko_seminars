<?php

namespace Tests\Feature\Public;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesEventWorld;
use Tests\TestCase;

/**
 * The registration submit is capped per IP (configurable, default 30/min —
 * many registrants share one public IP) and, always, 3/hour per email.
 * These numbers previously had no test at all.
 */
class RegistrationRateLimitTest extends TestCase
{
    use CreatesEventWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->makeEvent();
    }

    private function submit(string $email): TestResponse
    {
        return $this->post(route('event.register.store'), [
            'first_name' => 'Thabo', 'last_name' => 'Mokoena', 'email' => $email,
            'mobile' => '0821234567', 'organisation' => 'ABC Municipality', 'job_title' => 'IT Manager',
            'attendance_mode' => 'online', 'interests' => ['AI'],
            'networking_consent' => false, 'lumko_marketing_consent' => false,
        ]);
    }

    public function test_the_default_per_ip_cap_is_thirty_a_minute(): void
    {
        $this->assertSame(30, config('seminar.registration.ip_per_minute'));

        for ($i = 1; $i <= 30; $i++) {
            $this->submit("person{$i}@example.com")->assertRedirect();
        }

        $this->submit('person31@example.com')->assertStatus(429);
    }

    public function test_the_per_ip_cap_follows_configuration(): void
    {
        config(['seminar.registration.ip_per_minute' => 3]);

        foreach (['a', 'b', 'c'] as $name) {
            $this->submit("{$name}@example.com")->assertRedirect();
        }

        $this->submit('d@example.com')->assertStatus(429);
    }

    public function test_one_email_is_limited_to_three_submits_an_hour_even_with_a_generous_ip_cap(): void
    {
        config(['seminar.registration.ip_per_minute' => 100]);

        for ($i = 0; $i < 3; $i++) {
            $this->submit('same@example.com')->assertRedirect();
        }

        $this->submit('same@example.com')->assertStatus(429);

        // A different address from the same IP is unaffected.
        $this->submit('someone.else@example.com')->assertRedirect();
    }

    public function test_the_stepper_check_is_never_limited_by_the_per_email_cap(): void
    {
        config(['seminar.registration.ip_per_minute' => 1]);

        for ($step = 1; $step <= 7; $step++) {
            $this->postJson(route('event.register.validate-step'), [
                'step' => $step, 'email' => 'walker@example.com', 'first_name' => 'W', 'last_name' => 'K',
                'mobile' => '0821234567', 'organisation' => 'X', 'job_title' => 'Y', 'attendance_mode' => 'online',
            ])->assertOk();
        }
    }
}
