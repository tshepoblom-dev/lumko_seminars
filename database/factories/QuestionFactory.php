<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\Question;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class QuestionFactory extends Factory
{
    protected $model = Question::class;

    public function definition(): array
    {
        $body = rtrim($this->faker->sentence(8), '.').'?';

        return [
            'event_id' => Event::factory(),
            'user_id' => User::factory(),
            'body' => $body,
            'status' => 'submitted',
            'normalized_hash' => hash('sha256', strtolower($body)),
        ];
    }

    public function approved(): static
    {
        return $this->state(['status' => 'approved']);
    }
}
