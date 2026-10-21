<?php

namespace Tests\Feature;

use App\Models\Resource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** T-BOOT-2: the Resource model must be factory-able. */
class ResourceFactoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_resource_factory_creates_a_valid_resource(): void
    {
        $resource = Resource::factory()->create();

        $this->assertModelExists($resource);
    }
}
