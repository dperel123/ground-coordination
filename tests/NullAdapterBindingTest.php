<?php

namespace Nyartcc\GroundCoordination\Tests;

use Nyartcc\GroundCoordination\Adapters\NullAirportData;
use Nyartcc\GroundCoordination\Adapters\NullBoardNotifier;
use Nyartcc\GroundCoordination\Adapters\NullPilotIdentity;
use Nyartcc\GroundCoordination\Adapters\NullTrafficSource;
use Nyartcc\GroundCoordination\Contracts\AirportData;
use Nyartcc\GroundCoordination\Contracts\BoardNotifier;
use Nyartcc\GroundCoordination\Contracts\PilotIdentity;
use Nyartcc\GroundCoordination\Contracts\TrafficSource;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NullAdapterBindingTest extends TestCase
{
    /** @return array<string, array{class-string, class-string}> */
    public static function contracts(): array
    {
        return [
            'PilotIdentity' => [PilotIdentity::class, NullPilotIdentity::class],
            'TrafficSource' => [TrafficSource::class, NullTrafficSource::class],
            'AirportData' => [AirportData::class, NullAirportData::class],
            'BoardNotifier' => [BoardNotifier::class, NullBoardNotifier::class],
        ];
    }

    #[DataProvider('contracts')]
    public function test_each_contract_resolves_to_its_null_adapter(string $contract, string $adapter): void
    {
        $this->assertInstanceOf($adapter, $this->app->make($contract));
    }
}
