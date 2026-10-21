<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // B16 — never create a known default credential outside local/testing.
        // Production/staging admins are created only via `php artisan
        // lumko:create-admin`, which forces an operator-chosen strong
        // password. This seeder used to run unconditionally in every
        // environment, including production — that was a live bug.
        if (app()->environment(['local', 'testing'])) {
            User::firstOrCreate(
                ['email' => 'admin@lumko.cloud'],
                [
                    'name' => 'Lumko Admin',
                    'first_name' => 'Lumko',
                    'last_name' => 'Admin',
                    'password' => bcrypt('password'),
                    'role' => UserRole::SuperAdmin,
                    'email_verified_at' => now(),
                ]
            );
        } else {
            $this->command?->getOutput()->warning(
                'Skipped demo admin seeding: not running in local/testing. '.
                'Use `php artisan lumko:create-admin` to create a staff account.'
            );
        }

        $this->call(PowerOfDataEventSeeder::class);
    }
}
