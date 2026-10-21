<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\Feedback;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class FeedbackFactory extends Factory
{
    protected $model = Feedback::class;

    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'user_id' => User::factory(),
            'rating' => $this->faker->numberBetween(1, 5),
            'biggest_takeaway' => $this->faker->sentence(),
            'wants_contact' => false,
        ];
    }

    public function wantsContact(): static
    {
        return $this->state(['wants_contact' => true, 'contact_topics' => ['Automation']]);
    }
}
