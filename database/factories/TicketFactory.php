<?php

namespace Database\Factories;

use App\Models\EventRegistration;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Factories\Factory;

class TicketFactory extends Factory
{
    protected $model = Ticket::class;

    public function definition(): array
    {
        return [
            'event_registration_id' => EventRegistration::factory()->inPerson(),
            // The model encrypts `code` and derives `code_hash` on save.
            'code' => strtoupper($this->faker->unique()->bothify('????-########')),
            'status' => 'pending',
        ];
    }

    public function confirmed(): static
    {
        return $this->state(['status' => 'confirmed', 'issued_at' => now()]);
    }
}
