<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\Voucher;
use App\Support\VoucherCode;
use Illuminate\Database\Eloquent\Factories\Factory;

class VoucherFactory extends Factory
{
    protected $model = Voucher::class;

    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            // The model encrypts `code` and derives `code_hash` on save.
            'code' => VoucherCode::generate(),
            'recipient_name' => $this->faker->name(),
            'recipient_email' => null,
            'email_locked' => false,
        ];
    }

    public function lockedTo(string $email): static
    {
        return $this->state(['recipient_email' => strtolower($email), 'email_locked' => true]);
    }

    /** The redeemed_at half only — tests that need the registration set voucher_id themselves. */
    public function redeemed(): static
    {
        return $this->state(['redeemed_at' => now()]);
    }

    public function revoked(): static
    {
        return $this->state(['revoked_at' => now()]);
    }
}
