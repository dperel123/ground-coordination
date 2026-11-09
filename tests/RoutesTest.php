<?php

namespace Nyartcc\GroundCoordination\Tests;

use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class RoutesTest extends TestCase
{
    public function test_no_ground_coordination_route_is_registered_yet(): void
    {
        $names = collect($this->app['router']->getRoutes()->getRoutes())
            ->map(fn (Route $route) => $route->getName())
            ->filter(fn (?string $name) => $name !== null && Str::startsWith($name, 'ground-coordination.'));

        $this->assertSame([], $names->values()->all());
    }
}
