<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\ProgrammeSession;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProgrammeSessionFactory extends Factory
{
    protected $model = ProgrammeSession::class;

    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'title' => $this->faker->sentence(4),
            'description' => $this->faker->sentence(),
            'start_time' => now()->addWeek(),
            'end_time' => now()->addWeek()->addMinutes(20),
            'sort_order' => 0,
        ];
    }
}
