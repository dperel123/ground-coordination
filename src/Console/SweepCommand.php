<?php

namespace Nyartcc\GroundCoordination\Console;

use Illuminate\Console\Command;
use Nyartcc\GroundCoordination\Models\Airport;
use Nyartcc\GroundCoordination\Services\GroundSweep;

class SweepCommand extends Command
{
    protected $signature = 'ground-coordination:sweep';

    protected $description = 'Refresh the ground picture (aircraft, stands, phases) at every active airport';

    public function handle(GroundSweep $sweep): int
    {
        $rows = Airport::where('active', true)->orderBy('icao')->get()
            ->map(function (Airport $airport) use ($sweep) {
                $counts = $sweep->run($airport);

                return [$airport->icao, $counts->seen, $counts->atStand, $counts->moving, $counts->pruned];
            });

        if ($rows->isEmpty()) {
            $this->warn('No active airports.');

            return self::SUCCESS;
        }

        $this->table(['airport', 'seen', 'at_stand', 'moving', 'pruned'], $rows->all());

        return self::SUCCESS;
    }
}
