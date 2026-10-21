<?php

namespace Database\Factories;

use App\Models\Partner;
use Illuminate\Database\Eloquent\Factories\Factory;

class PartnerFactory extends Factory
{
    protected $model = Partner::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->company(),
            'website' => 'https://'.$this->faker->domainName(),
            'description' => $this->faker->paragraph(),
            'contact_email' => $this->faker->companyEmail(),
        ];
    }
}
