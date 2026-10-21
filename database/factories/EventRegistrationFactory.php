<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class EventRegistrationFactory extends Factory
{
    protected $model = EventRegistration::class;

    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'user_id' => User::factory(),
            'attendance_mode' => 'online',
            'status' => 'registered',
            // public_id is assigned by the model's creating hook.
        ];
    }

    public function inPerson(): static
    {
        return $this->state(['attendance_mode' => 'in_person']);
    }
}
