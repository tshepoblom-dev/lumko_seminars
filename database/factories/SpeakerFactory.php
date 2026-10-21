<?php

namespace Database\Factories;

use App\Models\Speaker;
use Illuminate\Database\Eloquent\Factories\Factory;

class SpeakerFactory extends Factory
{
    protected $model = Speaker::class;

    public function definition(): array
    {
        return [
            'user_id' => null,
            'name' => $this->faker->name(),
            'title' => $this->faker->jobTitle(),
            'organisation' => $this->faker->company(),
            'bio' => $this->faker->paragraph(),
        ];
    }
}
