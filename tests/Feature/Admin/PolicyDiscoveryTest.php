<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\CheckIn;
use App\Models\Connection;
use App\Models\EventRegistration;
use App\Models\Ticket;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * B11 — guards against exactly the bug this caught once already: a
 * policy file whose class name doesn't match Laravel's auto-discovery
 * convention (Model -> ModelPolicy) resolves to "no policy", silently,
 * not an error. RegistrationPolicy vs EventRegistration was wrong this
 * way until this test (and a manual Gate::getPolicyFor check) caught it.
 */
class PolicyDiscoveryTest extends TestCase
{
    public static function modelsWithPolicies(): array
    {
        return [
            [EventRegistration::class],
            [Ticket::class],
            [CheckIn::class],
            [Connection::class],
            [AuditLog::class],
        ];
    }

    #[DataProvider('modelsWithPolicies')]
    public function test_model_has_a_discoverable_policy(string $model): void
    {
        $this->assertNotNull(
            Gate::getPolicyFor($model),
            "No policy auto-discovered for {$model} — check the policy class name matches Laravel's {$model}Policy convention."
        );
    }
}
