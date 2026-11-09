<?php

namespace Nyartcc\GroundCoordination\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use JsonException;
use Nyartcc\GroundCoordination\Actions\ImportAirport;
use Nyartcc\GroundCoordination\Actions\ImportStands;
use Nyartcc\GroundCoordination\Dto\StandImportSummary;

class ImportCommand extends Command
{
    protected $signature = 'ground-coordination:import {icao : The airport, e.g. KJFK}';

    protected $description = 'Upsert an airport and its stands from the package reference data';

    public function handle(): int
    {
        $icao = strtoupper((string) $this->argument('icao'));
        $base = rtrim((string) config('ground-coordination.data_path'), '/');

        try {
            $airportRecord = $this->readJson("{$base}/airports/{$icao}.json");
            $stands = $this->readJson("{$base}/stands/{$icao}.geojson");
        } catch (JsonException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $summary = DB::transaction(function () use ($airportRecord, $stands) {
            $airport = app(ImportAirport::class)($airportRecord);

            return app(ImportStands::class)($airport, $stands);
        });

        $this->report($icao, $summary);

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    private function readJson(string $path): array
    {
        if (! is_file($path)) {
            throw new JsonException("No reference data at {$path}.");
        }

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    private function report(string $icao, StandImportSummary $summary): void
    {
        $this->info("{$icao} stands imported.");
        $this->table(['added', 'updated', 'unchanged', 'skipped-without-ref', 'skipped-ambiguous'], [[
            $summary->added, $summary->updated, $summary->unchanged,
            $summary->skippedWithoutRef, $summary->skippedAmbiguous,
        ]]);
    }
}
