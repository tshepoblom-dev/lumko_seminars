<?php

namespace Database\Factories;

use App\Models\Announcement;
use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class AnnouncementFactory extends Factory
{
    protected $model = Announcement::class;

    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'created_by' => User::factory(),
            'title' => $this->faker->sentence(4),
            'body' => $this->faker->paragraph(),
            'type' => 'event_update',
            'audience' => 'all',
            'published_at' => null, // a draft until published()
        ];
    }

    public function published(): static
    {
        return $this->state(['published_at' => now()->subMinute()]);
    }
}
