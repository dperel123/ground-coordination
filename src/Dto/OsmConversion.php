<?php

namespace Nyartcc\GroundCoordination\Dto;

/** What OsmStandConverter made of one Overpass response. */
final readonly class OsmConversion
{
    /**
     * @param  list<array<string, mixed>>  $stands  GeoJSON Point features, one per stand, sorted by name
     * @param  list<array<string, mixed>>  $ambiguous  GeoJSON Point features whose ref names more than one stand
     * @param  list<array<string, mixed>>  $twins  one record per collapsed group: name, members, distance_m, kept
     */
    public function __construct(
        public int $features,
        public int $withRef,
        public int $names,
        public array $stands,
        public array $ambiguous,
        public array $twins,
    ) {}

    public function ambiguousNames(): int
    {
        return count(array_unique(array_map(fn (array $feature) => $feature['properties']['ref'], $this->ambiguous)));
    }
}
