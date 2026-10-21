<?php

namespace Database\Factories;

use App\Models\ConsentRecord;
use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ConsentRecordFactory extends Factory
{
    protected $model = ConsentRecord::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'event_id' => Event::factory(),
            'type' => 'networking',
            'action' => 'granted',
            'purpose_key' => 'networking',
            'purpose_text' => 'Make my profile visible to other attendees.',
            'purpose_version' => 'v1',
            'source' => 'registration',
            'effective_at' => now(),
        ];
    }
}
