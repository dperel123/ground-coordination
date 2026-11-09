<?php

namespace Nyartcc\GroundCoordination;

use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Nyartcc\GroundCoordination\Adapters\NullAirportData;
use Nyartcc\GroundCoordination\Adapters\NullBoardNotifier;
use Nyartcc\GroundCoordination\Adapters\NullPilotIdentity;
use Nyartcc\GroundCoordination\Adapters\NullTrafficSource;
use Nyartcc\GroundCoordination\Contracts\AirportData;
use Nyartcc\GroundCoordination\Contracts\BoardNotifier;
use Nyartcc\GroundCoordination\Contracts\PilotIdentity;
use Nyartcc\GroundCoordination\Contracts\TrafficSource;

class GroundCoordinationServiceProvider extends ServiceProvider
{
    /**
     * The Null adapters, so the package boots on any host. bindIf() lets a
     * host's own binding win whichever provider registers first.
     *
     * @var array<class-string, class-string>
     */
    private const DEFAULT_ADAPTERS = [
        PilotIdentity::class => NullPilotIdentity::class,
        TrafficSource::class => NullTrafficSource::class,
        AirportData::class => NullAirportData::class,
        BoardNotifier::class => NullBoardNotifier::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/ground-coordination.php', 'ground-coordination');

        foreach (self::DEFAULT_ADAPTERS as $contract => $adapter) {
            $this->app->bindIf($contract, $adapter);
        }
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'ground-coordination');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        Livewire::addNamespace(
            'ground-coordination',
            classNamespace: 'Nyartcc\\GroundCoordination\\Livewire',
            classPath: __DIR__.'/Livewire',
            classViewPath: __DIR__.'/../resources/views/livewire',
        );
    }
}
