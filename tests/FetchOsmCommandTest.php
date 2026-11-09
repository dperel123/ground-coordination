<?php

namespace Nyartcc\GroundCoordination\Tests;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Nyartcc\GroundCoordination\Models\Stand;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FetchOsmCommandTest extends TestCase
{
    use RefreshDatabase;

    private const HEADERS = ['features', 'with ref', 'names', 'twins collapsed', 'ambiguous skipped'];

    /** A data directory only this test writes, removed in tearDown. */
    private string $dataPath;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->dataPath = sys_get_temp_dir().'/gc-fetch-'.bin2hex(random_bytes(6));
        mkdir($this->dataPath.'/airports', 0755, true);
        copy(__DIR__.'/../resources/data/airports/KJFK.json', $this->dataPath.'/airports/KJFK.json');
        config(['ground-coordination.data_path' => $this->dataPath]);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->dataPath);

        parent::tearDown();
    }

    public function test_it_fetches_the_airport_bbox_and_writes_both_files(): void
    {
        $this->fakeOverpass();

        $this->artisan('ground-coordination:fetch-osm', ['icao' => 'kjfk'])
            ->expectsOutputToContain('KJFK: wrote 7 stands and 4 ambiguous features.')
            ->expectsTable(self::HEADERS, [[14, 13, 9, 2, '4 (2 names)']])
            ->assertSuccessful();

        Http::assertSent(fn (Request $request) => $request->url() === 'https://overpass-api.de/api/interpreter'
            && $request->hasHeader('User-Agent', 'nyartcc-ground-coordination/0.1 (stand data import)')
            && $request['data'] === '[out:json][timeout:90];(node["aeroway"="parking_position"](40.62,-73.83,40.665,-73.745);'
                .'way["aeroway"="parking_position"](40.62,-73.83,40.665,-73.745););out geom;');
        $this->assertSame(['11A', '18', '2A', '37', '7M', 'B20A', 'B42'], $this->names('KJFK.geojson', 'name'));
        $this->assertSame(['90', '90', 'D2', 'D2'], $this->names('KJFK.ambiguous.geojson', 'ref'));
    }

    public function test_what_it_writes_is_what_the_importer_reads(): void
    {
        $this->fakeOverpass();
        $this->artisan('ground-coordination:fetch-osm', ['icao' => 'KJFK'])->assertSuccessful();

        $this->artisan('ground-coordination:import', ['icao' => 'KJFK'])
            ->expectsTable(['added', 'updated', 'unchanged', 'skipped-without-ref', 'skipped-ambiguous'], [[7, 0, 0, 0, 0]])
            ->assertSuccessful();

        $this->assertSame(['11A', '18', '2A', '37', '7M', 'B20A', 'B42'], Stand::orderBy('name')->pluck('name')->all());
    }

    public function test_the_bbox_option_wins_over_the_airport_file(): void
    {
        $this->fakeOverpass();

        $this->artisan('ground-coordination:fetch-osm', ['icao' => 'KXYZ', '--bbox' => '40.1, -73.9, 40.2, -73.8'])->assertSuccessful();

        Http::assertSent(fn (Request $request) => str_contains($request['data'], '(40.1,-73.9,40.2,-73.8)'));
        $this->assertFileExists($this->dataPath.'/stands/KXYZ.geojson');
    }

    public function test_the_twin_threshold_option_reaches_the_converter(): void
    {
        $this->fakeOverpass();

        $this->artisan('ground-coordination:fetch-osm', ['icao' => 'KJFK', '--twin-threshold-m' => '62'])
            ->expectsTable(self::HEADERS, [[14, 13, 9, 3, '2 (1 names)']])
            ->assertSuccessful();
    }

    public function test_no_bbox_anywhere_fails_before_any_request(): void
    {
        $this->artisan('ground-coordination:fetch-osm', ['icao' => 'KXYZ'])
            ->expectsOutputToContain('No bbox: pass --bbox')
            ->assertFailed();

        Http::assertNothingSent();
        $this->assertDirectoryDoesNotExist($this->dataPath.'/stands');
    }

    /** @return array<string, array{array<string, string>, string}> */
    public static function badOptions(): array
    {
        return [
            'three numbers' => [['--bbox' => '40.1,-73.9,40.2'], '--bbox must be four numbers'],
            'not a number' => [['--bbox' => '40.1,west,40.2,-73.8'], '--bbox must be four numbers'],
            'inverted' => [['--bbox' => '40.2,-73.9,40.1,-73.8'], 'south-west to north-east'],
            'negative threshold' => [['--twin-threshold-m' => '-1'], '--twin-threshold-m must be'],
            'word threshold' => [['--twin-threshold-m' => 'sixty'], '--twin-threshold-m must be'],
        ];
    }

    /** @param array<string, string> $options */
    #[DataProvider('badOptions')]
    public function test_a_bad_option_fails_before_any_request(array $options, string $message): void
    {
        $this->artisan('ground-coordination:fetch-osm', ['icao' => 'KJFK'] + $options)
            ->expectsOutputToContain($message)
            ->assertFailed();

        Http::assertNothingSent();
    }

    public function test_an_overpass_refusal_fails_and_leaves_existing_files_alone(): void
    {
        mkdir($this->dataPath.'/stands');
        file_put_contents($this->dataPath.'/stands/KJFK.geojson', 'existing');
        Http::fake(['overpass-api.de/*' => Http::response('<html>406 Not Acceptable</html>', 406)]);

        $this->artisan('ground-coordination:fetch-osm', ['icao' => 'KJFK'])->assertFailed();

        $this->assertSame('existing', file_get_contents($this->dataPath.'/stands/KJFK.geojson'));
        $this->assertFileDoesNotExist($this->dataPath.'/stands/KJFK.ambiguous.geojson');
    }

    private function fakeOverpass(): void
    {
        Http::fake(['overpass-api.de/*' => Http::response(
            (string) file_get_contents(__DIR__.'/fixtures/overpass/KJFK-subset.json'),
            200,
            ['Content-Type' => 'application/json'],
        )]);
    }

    /** @return list<string> */
    private function names(string $file, string $property): array
    {
        $collection = json_decode((string) file_get_contents($this->dataPath.'/stands/'.$file), true);

        return array_map(fn (array $feature) => $feature['properties'][$property], $collection['features']);
    }
}
