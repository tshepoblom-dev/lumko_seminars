<?php

namespace Database\Factories;

use App\Models\EventRegistration;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        return [
            'event_registration_id' => EventRegistration::factory()->inPerson(),
            'amount' => 150,
            'status' => 'pending',
        ];
    }

    public function status(string $status): static
    {
        return $this->state(['status' => $status]);
    }

    public function approved(): static
    {
        return $this->state(['status' => 'approved', 'reviewed_at' => now()]);
    }
}
