<?php

namespace Nyartcc\GroundCoordination\Tests;

use Nyartcc\GroundCoordination\Dto\OsmConversion;
use Nyartcc\GroundCoordination\Services\OsmStandConverter;
use Nyartcc\GroundCoordination\Support\GeoJson;
use Tests\TestCase;

/**
 * Against a real slice of the 2026-11-09 KJFK Overpass response. The expected
 * values are what the original hand conversion (Python) wrote to
 * resources/data/stands/KJFK*.geojson, so they are an independent oracle.
 */
class OsmStandConverterTest extends TestCase
{
    private const ATTRIBUTION = '© OpenStreetMap contributors, ODbL';

    public function test_it_counts_every_kind_of_feature(): void
    {
        $result = $this->convert(60);

        $this->assertSame([14, 13, 9, 2, 4, 2], [
            $result->features, $result->withRef, $result->names,
            count($result->twins), count($result->ambiguous), $result->ambiguousNames(),
        ]);
    }

    public function test_stands_are_sorted_by_name_and_carry_the_hand_converted_values(): void
    {
        $stands = $this->convert(60)->stands;

        $this->assertSame(['11A', '18', '2A', '37', '7M', 'B20A', 'B42'], array_map(fn (array $f) => $f['properties']['name'], $stands));
        $this->assertSame([
            'type' => 'Feature',
            'geometry' => ['type' => 'Point', 'coordinates' => [-73.7939452, 40.6416427]],
            'properties' => [
                'name' => '11A', 'area' => null, 'heading' => null,
                'source' => 'openstreetmap', 'attribution' => self::ATTRIBUTION, 'osm_id' => 'way/632238179',
            ],
        ], $stands[0], 'a single way: the mean of its vertices');
    }

    public function test_a_node_and_way_twin_keeps_the_node_and_a_way_pair_keeps_their_mean(): void
    {
        $result = $this->convert(60);
        $stands = collect($result->stands)->keyBy(fn (array $f) => $f['properties']['name']);

        $this->assertSame([-73.8117697, 40.6507564], $stands['2A']['geometry']['coordinates']);
        $this->assertSame('node/12539238962', $stands['2A']['properties']['osm_id']);
        $this->assertSame([-73.7824187, 40.6505982], $stands['7M']['geometry']['coordinates']);
        $this->assertSame('way/1011144693,way/1011144694', $stands['7M']['properties']['osm_id']);

        $twins = collect($result->twins)->keyBy('name');
        $this->assertSame(['node point', 31.0], [$twins['2A']['kept'], $twins['2A']['distance_m']]);
        $this->assertSame(['mean of members', 10.8], [$twins['7M']['kept'], $twins['7M']['distance_m']]);
        $this->assertSame(
            [['osm_id' => 'node/12539238962', 'lat' => 40.6507564, 'lon' => -73.8117697], ['osm_id' => 'way/1354939265', 'lat' => 40.6505094, 'lon' => -73.8115989]],
            $twins['2A']['members'],
        );
    }

    /**
     * Each of these three moves in the 7th decimal under one of two defects:
     * a plain array_sum() mean ("B20A", "37") or PHP's round() ("18", "37").
     */
    public function test_way_centroids_round_exactly_as_the_hand_conversion_did(): void
    {
        $stands = collect($this->convert(60)->stands)->keyBy(fn (array $f) => $f['properties']['name']);

        $this->assertSame([-73.7890072, 40.6491564], $stands['18']['geometry']['coordinates']);
        $this->assertSame([-73.7934346, 40.6491146], $stands['37']['geometry']['coordinates']);
        $this->assertSame([-73.7847678, 40.6420112], $stands['B20A']['geometry']['coordinates']);
    }

    public function test_a_ref_used_for_different_stands_is_set_aside_whole_with_its_raw_data(): void
    {
        $ambiguous = $this->convert(60)->ambiguous;

        $this->assertSame([
            [[-73.7848157, 40.6368525], ['ref' => '90', 'osm_id' => 'node/9316949000', 'group_spread_m' => 61.1]],
            [[-73.7840918, 40.6368704], ['ref' => '90', 'osm_id' => 'way/1009786343', 'group_spread_m' => 61.1]],
            [[-73.7746135, 40.642172], ['ref' => 'D2', 'osm_id' => 'node/9322616791', 'group_spread_m' => 2834.0]],
            [[-73.8071364, 40.648544], ['ref' => 'D2', 'osm_id' => 'way/1354939254', 'group_spread_m' => 2834.0]],
        ], array_map(fn (array $f) => [$f['geometry']['coordinates'], $f['properties']], $ambiguous));
    }

    public function test_the_twin_threshold_decides_both_ways(): void
    {
        $wide = $this->convert(62);
        $this->assertContains('90', array_map(fn (array $f) => $f['properties']['name'], $wide->stands), '61.1 m apart is a twin at 62');
        $this->assertSame(['D2'], array_values(array_unique(array_map(fn (array $f) => $f['properties']['ref'], $wide->ambiguous))));

        $tight = $this->convert(20);
        $this->assertNotContains('2A', array_map(fn (array $f) => $f['properties']['name'], $tight->stands), '31 m apart is ambiguous at 20');
        $this->assertSame(['90', 'D2', '2A'], array_values(array_unique(array_map(fn (array $f) => $f['properties']['ref'], $tight->ambiguous))));
    }

    public function test_an_empty_response_converts_to_nothing(): void
    {
        $result = app(OsmStandConverter::class)->convert(['elements' => []], 60);

        $this->assertSame([0, 0, 0, [], [], []], [$result->features, $result->withRef, $result->names, $result->stands, $result->ambiguous, $result->twins]);
    }

    public function test_the_encoder_writes_the_data_file_layout(): void
    {
        $json = GeoJson::featureCollection([[
            'type' => 'Feature',
            'geometry' => ['type' => 'Point', 'coordinates' => [-73.0, 40.0]],
            'properties' => ['osm_id' => 'node/1', 'attribution' => self::ATTRIBUTION],
        ]]);

        $this->assertSame(<<<'JSON'
{
  "type": "FeatureCollection",
  "features": [
    {
      "type": "Feature",
      "geometry": {
        "type": "Point",
        "coordinates": [
          -73.0,
          40.0
        ]
      },
      "properties": {
        "osm_id": "node/1",
        "attribution": "© OpenStreetMap contributors, ODbL"
      }
    }
  ]
}
JSON, $json);
    }

    private function convert(float $threshold): OsmConversion
    {
        $overpass = json_decode((string) file_get_contents(__DIR__.'/fixtures/overpass/KJFK-subset.json'), true);

        return app(OsmStandConverter::class)->convert($overpass, $threshold);
    }
}
