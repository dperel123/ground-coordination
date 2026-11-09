<?php

namespace Nyartcc\GroundCoordination\Tests;

use Nyartcc\GroundCoordination\GroundCoordinationServiceProvider;
use Tests\TestCase;

class ServiceProviderTest extends TestCase
{
    public function test_the_provider_is_loaded_and_its_config_is_merged(): void
    {
        $this->assertArrayHasKey(GroundCoordinationServiceProvider::class, $this->app->getLoadedProviders());
        $this->assertSame(15, config('ground-coordination.poll_seconds'));
    }
}
