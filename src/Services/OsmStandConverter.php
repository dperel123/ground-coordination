<?php

namespace Nyartcc\GroundCoordination\Services;

use Nyartcc\GroundCoordination\Dto\OsmConversion;
use Nyartcc\GroundCoordination\Support\Haversine;

/**
 * Turns an Overpass `out geom` response of aeroway=parking_position nodes
 * and ways into stand features. Pure: no network, no files.
 *
 * Features are grouped by `ref` (no ref: dropped). A group of one is a stand.
 * A group whose members all lie within the twin threshold is one stand mapped
 * twice and collapses to the node's point when exactly one member is a node,
 * else to the mean of the members. Any other group is the same ref used for
 * different stands: it is set aside whole as ambiguous, never guessed at.
 * A way's point is the mean of its vertices (stand ways are lead-in lines).
 */
class OsmStandConverter
{
    private const COORDINATE_DECIMALS = 7;

    /** @param array<string, mixed> $overpass */
    public function convert(array $overpass, float $twinThresholdMeters): OsmConversion
    {
        $elements = $overpass['elements'] ?? [];
        $groups = $this->groupByRef($elements);
        $stands = $ambiguous = $twins = [];

        foreach ($groups as $ref => $members) {
            $ref = (string) $ref; // PHP turns numeric-string keys ("1") into ints
            $points = array_map(fn (array $element) => $this->pointOf($element), $members);
            $spread = $this->spread($points);

            if (count($members) > 1 && $spread > $twinThresholdMeters) {
                array_push($ambiguous, ...$this->ambiguousFeatures($ref, $members, $points, $spread));

                continue;
            }
            $stands[] = $this->standFeature($ref, $members, $points, $spread, $twins);
        }
        usort($stands, fn (array $a, array $b) => strcmp($a['properties']['name'], $b['properties']['name']));

        return new OsmConversion(count($elements), array_sum(array_map('count', $groups)), count($groups), $stands, $ambiguous, $twins);
    }

    /**
     * @param  list<array<string, mixed>>  $elements
     * @return array<array-key, list<array<string, mixed>>> ref => members, in first-seen order
     */
    private function groupByRef(array $elements): array
    {
        $groups = [];
        foreach ($elements as $element) {
            $ref = trim((string) ($element['tags']['ref'] ?? ''));
            if ($ref !== '') {
                $groups[$ref][] = $element;
            }
        }

        return $groups;
    }

    /**
     * @param  list<array<string, mixed>>  $members
     * @param  list<array{float, float}>  $points
     * @param  list<array<string, mixed>>  $twins  appended to when the group collapses
     * @return array<string, mixed>
     */
    private function standFeature(string $ref, array $members, array $points, float $spread, array &$twins): array
    {
        $nodeIndexes = array_keys(array_filter($members, fn (array $element) => $element['type'] === 'node'));

        if (count($members) === 1) {
            [$point, $osmId] = [$points[0], $this->osmId($members[0])];
        } elseif (count($nodeIndexes) === 1) {
            [$point, $osmId, $kept] = [$points[$nodeIndexes[0]], $this->osmId($members[$nodeIndexes[0]]), 'node point'];
        } else {
            [$point, $osmId, $kept] = [$this->mean($points), implode(',', array_map(fn (array $element) => $this->osmId($element), $members)), 'mean of members'];
        }

        if (isset($kept)) {
            $twins[] = $this->twinRecord($ref, $members, $points, $spread, $kept, $point);
        }

        return $this->feature($point, [
            'name' => $ref,
            'area' => null,
            'heading' => null,
            'source' => config('ground-coordination.osm.source'),
            'attribution' => config('ground-coordination.osm.attribution'),
            'osm_id' => $osmId,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $members
     * @param  list<array{float, float}>  $points
     * @return list<array<string, mixed>>
     */
    private function ambiguousFeatures(string $ref, array $members, array $points, float $spread): array
    {
        return array_map(fn (array $element, array $point) => $this->feature($point, [
            'ref' => $ref,
            'osm_id' => $this->osmId($element),
            'group_spread_m' => $this->rounded($spread, 1),
        ]), $members, $points);
    }

    /**
     * @param  list<array<string, mixed>>  $members
     * @param  list<array{float, float}>  $points
     * @param  array{float, float}  $keptPoint
     * @return array<string, mixed>
     */
    private function twinRecord(string $ref, array $members, array $points, float $spread, string $kept, array $keptPoint): array
    {
        return [
            'name' => $ref,
            'members' => array_map(fn (array $element, array $point) => [
                'osm_id' => $this->osmId($element),
                'lat' => $this->rounded($point[0], self::COORDINATE_DECIMALS),
                'lon' => $this->rounded($point[1], self::COORDINATE_DECIMALS),
            ], $members, $points),
            'distance_m' => $this->rounded($spread, 1),
            'kept' => $kept,
            'kept_lat' => $this->rounded($keptPoint[0], self::COORDINATE_DECIMALS),
            'kept_lon' => $this->rounded($keptPoint[1], self::COORDINATE_DECIMALS),
        ];
    }

    /**
     * @param  array<string, mixed>  $element
     * @return array{float, float} lat, lon
     */
    private function pointOf(array $element): array
    {
        if ($element['type'] === 'node') {
            return [(float) $element['lat'], (float) $element['lon']];
        }

        return $this->mean(array_map(fn (array $vertex) => [(float) $vertex['lat'], (float) $vertex['lon']], $element['geometry']));
    }

    /**
     * @param  list<array{float, float}>  $points
     * @return array{float, float}
     */
    private function mean(array $points): array
    {
        return [$this->sum(array_column($points, 0)) / count($points), $this->sum(array_column($points, 1)) / count($points)];
    }

    /**
     * Neumaier-compensated sum, the algorithm Python 3.12's sum() uses, which
     * wrote the data files. A plain array_sum() differs in the last bits, and
     * that is enough to move a rounded 7th decimal.
     *
     * @param  list<float>  $values
     */
    private function sum(array $values): float
    {
        $total = $compensation = 0.0;
        foreach ($values as $value) {
            $next = $total + $value;
            $compensation += abs($total) >= abs($value) ? ($total - $next) + $value : ($value - $next) + $total;
            $total = $next;
        }

        return $total + $compensation;
    }

    /** @param list<array{float, float}> $points the largest pairwise distance, 0 for one point */
    private function spread(array $points): float
    {
        $max = 0.0;
        foreach ($points as $i => $a) {
            foreach (array_slice($points, $i + 1) as $b) {
                $max = max($max, Haversine::meters($a[0], $a[1], $b[0], $b[1]));
            }
        }

        return $max;
    }

    /**
     * @param  array{float, float}  $point
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    private function feature(array $point, array $properties): array
    {
        return [
            'type' => 'Feature',
            'geometry' => ['type' => 'Point', 'coordinates' => [
                $this->rounded($point[1], self::COORDINATE_DECIMALS),
                $this->rounded($point[0], self::COORDINATE_DECIMALS),
            ]],
            'properties' => $properties,
        ];
    }

    /**
     * Rounds the exact binary value, as printf does. PHP's round() instead
     * rounds as if the shortest decimal form were exact, so a mean printed as
     * ...645 goes up where printf takes it down. The data files were written
     * with correct rounding, and a re-fetch must not move every such stand a
     * centimetre.
     */
    private function rounded(float $value, int $decimals): float
    {
        return (float) sprintf("%.{$decimals}F", $value);
    }

    /** @param array<string, mixed> $element */
    private function osmId(array $element): string
    {
        return $element['type'].'/'.$element['id'];
    }
}
