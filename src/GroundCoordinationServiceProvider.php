<?php

namespace Nyartcc\GroundCoordination;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Nyartcc\GroundCoordination\Adapters\NullAirportData;
use Nyartcc\GroundCoordination\Adapters\NullBoardNotifier;
use Nyartcc\GroundCoordination\Adapters\NullPilotIdentity;
use Nyartcc\GroundCoordination\Adapters\VatsimDatafeedTrafficSource;
use Nyartcc\GroundCoordination\Console\FetchOsmCommand;
use Nyartcc\GroundCoordination\Console\ImportCommand;
use Nyartcc\GroundCoordination\Console\SweepCommand;
use Nyartcc\GroundCoordination\Contracts\AirportData;
use Nyartcc\GroundCoordination\Contracts\BoardNotifier;
use Nyartcc\GroundCoordination\Contracts\PilotIdentity;
use Nyartcc\GroundCoordination\Contracts\TrafficSource;

class GroundCoordinationServiceProvider extends ServiceProvider
{
    /**
     * The default adapters, so the package boots on any host: the VATSIM data
     * feed for traffic, Null adapters for the rest. bindIf() lets a host's
     * own binding win whichever provider registers first.
     *
     * @var array<class-string, class-string>
     */
    private const DEFAULT_ADAPTERS = [
        PilotIdentity::class => NullPilotIdentity::class,
        TrafficSource::class => VatsimDatafeedTrafficSource::class,
        AirportData::class => NullAirportData::class,
        BoardNotifier::class => NullBoardNotifier::class,
    ];

    /**
     * The intervals the scheduler offers, in seconds, and the Event method
     * that sets each (60 = everyMinute).
     *
     * @var array<int, string>
     */
    private const FREQUENCIES = [
        1 => 'everySecond', 2 => 'everyTwoSeconds', 5 => 'everyFiveSeconds', 10 => 'everyTenSeconds',
        15 => 'everyFifteenSeconds', 20 => 'everyTwentySeconds', 30 => 'everyThirtySeconds', 60 => 'everyMinute',
    ];

    /**
     * The scheduler method whose interval is closest to $seconds; a tie goes
     * to the more frequent one.
     */
    public static function frequencyFor(int $seconds): string
    {
        $closest = array_reduce(array_keys(self::FREQUENCIES), fn (?int $best, int $candidate) => $best === null
            || abs($candidate - $seconds) < abs($best - $seconds) ? $candidate : $best);

        return self::FREQUENCIES[$closest];
    }

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

        if ($this->app->runningInConsole()) {
            $this->commands([ImportCommand::class, FetchOsmCommand::class, SweepCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            if (! config('ground-coordination.enabled')) {
                return;
            }

            $event = $schedule->command(SweepCommand::class);
            $event->{self::frequencyFor((int) config('ground-coordination.poll_seconds'))}();
            $event->withoutOverlapping((int) config('ground-coordination.sweep_lock_minutes'))->onOneServer();
        });

        Livewire::addNamespace(
            'ground-coordination',
            classNamespace: 'Nyartcc\\GroundCoordination\\Livewire',
            classPath: __DIR__.'/Livewire',
            classViewPath: __DIR__.'/../resources/views/livewire',
        );
    }
}
