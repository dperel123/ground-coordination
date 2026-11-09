<?php

namespace Nyartcc\GroundCoordination\Actions;

use Illuminate\Support\Collection;
use Nyartcc\GroundCoordination\Dto\StandImportSummary;
use Nyartcc\GroundCoordination\Models\Airport;
use Nyartcc\GroundCoordination\Models\Stand;

/**
 * Upserts an airport's stands from a GeoJSON FeatureCollection of Points,
 * matched on (airport, name). Never deletes a stand missing from the file,
 * and never touches `active` or `radius_m` (curated on the row). A name that
 * occurs more than once in the file is skipped: which point is right is a
 * hand-correction question, not the importer's.
 */
class ImportStands
{
    /** @param array<string, mixed> $featureCollection */
    public function __invoke(Airport $airport, array $featureCollection): StandImportSummary
    {
        $summary = new StandImportSummary;
        $features = collect($featureCollection['features'] ?? []);
        $named = $features->filter(fn (array $feature) => $this->nameOf($feature) !== null);
        $summary->skippedWithoutRef = $features->count() - $named->count();
        $counts = $named->countBy(fn (array $feature) => $this->nameOf($feature));

        $named->each(function (array $feature) use ($airport, $counts, $summary) {
            if ($counts[$this->nameOf($feature)] > 1) {
                $summary->skippedAmbiguous++;

                return;
            }
            $this->upsert($airport, $feature, $summary);
        });

        return $summary;
    }

    /** @param array<string, mixed> $feature */
    private function upsert(Airport $airport, array $feature, StandImportSummary $summary): void
    {
        $stand = Stand::firstOrNew(['airport_id' => $airport->id, 'name' => $this->nameOf($feature)]);
        $stand->fill($this->attributesOf($feature));

        match (true) {
            ! $stand->exists => $summary->added++,
            $stand->isDirty() => $summary->updated++,
            default => $summary->unchanged++,
        };
        $stand->save();
    }

    /** @param array<string, mixed> $feature */
    private function nameOf(array $feature): ?string
    {
        $name = trim((string) ($feature['properties']['name'] ?? ''));

        return $name === '' ? null : $name;
    }

    /**
     * @param  array<string, mixed>  $feature
     * @return array<string, mixed>
     */
    private function attributesOf(array $feature): array
    {
        $properties = new Collection($feature['properties'] ?? []);
        [$lon, $lat] = $feature['geometry']['coordinates'];

        return [
            'lat' => (float) $lat,
            'lon' => (float) $lon,
            'area' => $properties->get('area'),
            'heading' => $properties->get('heading'),
            'source' => (string) $properties->get('source'),
            'attribution' => (string) $properties->get('attribution'),
        ];
    }
}
