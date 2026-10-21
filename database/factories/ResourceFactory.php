<?php

namespace Database\Factories;

use App\Models\Event;
use Illuminate\Database\Eloquent\Factories\Factory;

class ResourceFactory extends Factory
{
    protected $model = \App\Models\Resource::class;

    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'title' => $this->faker->sentence(3),
            'description' => $this->faker->sentence(),
            'category' => 'before_event',
            'is_published' => false,
        ];
    }
}
