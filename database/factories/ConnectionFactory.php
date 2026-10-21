<?php

namespace Database\Factories;

use App\Models\Connection;
use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ConnectionFactory extends Factory
{
    protected $model = Connection::class;

    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'requester_id' => User::factory(),
            'recipient_id' => User::factory(),
            'status' => 'pending',
        ];
    }

    public function accepted(): static
    {
        return $this->state(['status' => 'accepted']);
    }
}
