<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\Poll;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class PollFactory extends Factory
{
    protected $model = Poll::class;

    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'created_by' => User::factory(),
            'question' => rtrim($this->faker->sentence(6), '.').'?',
            'status' => 'draft',
            'show_results_live' => false,
        ];
    }

    public function open(): static
    {
        return $this->state(['status' => 'open', 'opened_at' => now()]);
    }
}
