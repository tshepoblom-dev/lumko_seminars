<?php

namespace Database\Factories;

use App\Models\Event;
use Illuminate\Database\Eloquent\Factories\Factory;

class EventFactory extends Factory
{
    protected $model = Event::class;

    public function definition(): array
    {
        $name = $this->faker->sentence(3);

        return [
            'name' => $name,
            'subtitle' => $this->faker->sentence(6),
            'slug' => str($name)->slug().'-'.$this->faker->unique()->numberBetween(1, 100000),
            'description' => $this->faker->paragraph(),
            'venue_name' => $this->faker->company(),
            'venue_address' => $this->faker->address(),
            'starts_at' => now()->addWeek(),
            'ends_at' => now()->addWeek()->addHours(2),
            'online_price' => 0,
            'in_person_price' => 150,
            'status' => 'draft',
        ];
    }
}
