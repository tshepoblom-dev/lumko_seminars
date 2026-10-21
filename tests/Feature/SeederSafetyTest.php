<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** T-SEED-1 (part): no default credentials may ever be created in production. */
class SeederSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_admin_is_not_created_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        // --force bypasses Artisan's own production confirmation prompt
        // (Illuminate\Console\ConfirmableTrait) so this test isolates the
        // seeder's *own* environment gate — defence in depth: the admin
        // must not be created even if someone force-runs the seeder.
        $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true]);

        $this->assertFalse(User::where('email', 'admin@lumko.cloud')->exists());
    }

    public function test_default_admin_is_only_created_in_local_or_testing(): void
    {
        $this->seed(DatabaseSeeder::class); // environment is "testing"

        $this->assertTrue(User::where('email', 'admin@lumko.cloud')->exists());
    }
}
