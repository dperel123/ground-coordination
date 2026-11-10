<?php

namespace Nyartcc\GroundCoordination\Tests;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nyartcc\GroundCoordination\Models\Airport;
use Nyartcc\GroundCoordination\Models\Stand;
use Tests\TestCase;

class ImportCommandTest extends TestCase
{
    use RefreshDatabase;

    private const HEADERS = ['added', 'updated', 'unchanged', 'skipped-without-ref', 'skipped-ambiguous'];

    /** A data directory only this test writes, removed in tearDown. */
    private string $dataPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dataPath = sys_get_temp_dir().'/gc-import-'.bin2hex(random_bytes(6));
        (new Filesystem)->copyDirectory(__DIR__.'/fixtures', $this->dataPath);
        config(['ground-coordination.data_path' => $this->dataPath]);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->dataPath);

        parent::tearDown();
    }

    public function test_it_imports_the_airport_and_every_named_stand(): void
    {
        $this->artisan('ground-coordination:import', ['icao' => 'ktst'])
            ->expectsTable(self::HEADERS, [[4, 0, 0, 1, 0]])
            ->assertSuccessful();

        $airport = Airport::where('icao', 'KTST')->sole();
        $this->assertSame('Test Field', $airport->name);
        $this->assertSame(20, $airport->elevation_ft);
        $this->assertSame(['A1', 'A2', 'B1', 'B2'], $airport->stands()->orderBy('name')->pluck('name')->all());
        $a1 = $airport->stands()->where('name', 'A1')->sole();
        $this->assertSame([40.0001, -73.0001, 'Terminal A', 'fixture', 'test fixture'], [$a1->lat, $a1->lon, $a1->area, $a1->source, $a1->attribution]);
    }

    public function test_the_ambiguous_backlog_file_is_never_read(): void
    {
        $this->artisan('ground-coordination:import', ['icao' => 'KTST'])->assertSuccessful();

        $this->assertFalse(Stand::where('name', 'ZZ9')->exists());
    }

    public function test_a_second_run_changes_nothing(): void
    {
        $this->artisan('ground-coordination:import', ['icao' => 'KTST'])->assertSuccessful();
        $before = Stand::orderBy('id')->get(['id', 'name', 'lat', 'lon', 'updated_at'])->toArray();

        $this->artisan('ground-coordination:import', ['icao' => 'KTST'])
            ->expectsTable(self::HEADERS, [[0, 0, 4, 1, 0]])
            ->assertSuccessful();

        $this->assertSame($before, Stand::orderBy('id')->get(['id', 'name', 'lat', 'lon', 'updated_at'])->toArray());
        $this->assertSame(1, Airport::count());
    }

    public function test_a_moved_stand_is_updated_in_place_and_a_vanished_one_is_kept(): void
    {
        $this->artisan('ground-coordination:import', ['icao' => 'KTST'])->assertSuccessful();
        $b1 = Stand::where('name', 'B1')->sole();
        Stand::where('name', 'A2')->sole()->forceFill(['active' => false, 'radius_m' => 25])->save();

        $this->rewriteStands(function (array $features) {
            $features[2]['geometry']['coordinates'] = [-73.0010, 40.0015]; // B1 moved
            unset($features[0]); // A1 gone from the file

            return array_values($features);
        });

        $this->artisan('ground-coordination:import', ['icao' => 'KTST'])
            ->expectsTable(self::HEADERS, [[0, 1, 2, 1, 0]])
            ->assertSuccessful();

        $this->assertSame(4, Stand::count());
        $moved = Stand::where('name', 'B1')->sole();
        $this->assertSame([$b1->id, 40.0015, -73.0010], [$moved->id, $moved->lat, $moved->lon]);
        $this->assertTrue(Stand::where('name', 'A1')->exists());
        $curated = Stand::where('name', 'A2')->sole();
        $this->assertSame([false, 25], [$curated->active, $curated->radius_m]);
    }

    public function test_a_name_repeated_in_the_file_is_skipped_not_overwritten(): void
    {
        $this->rewriteStands(function (array $features) {
            $features[] = ['type' => 'Feature', 'geometry' => ['type' => 'Point', 'coordinates' => [-73.002, 40.002]], 'properties' => ['name' => 'B2', 'source' => 'fixture', 'attribution' => 'test fixture']];

            return $features;
        });

        $this->artisan('ground-coordination:import', ['icao' => 'KTST'])
            ->expectsTable(self::HEADERS, [[3, 0, 0, 1, 2]])
            ->assertSuccessful();

        $this->assertFalse(Stand::where('name', 'B2')->exists());
    }

    public function test_a_missing_airport_file_fails_and_writes_nothing(): void
    {
        $this->artisan('ground-coordination:import', ['icao' => 'KXXX'])
            ->expectsOutputToContain('No reference data at')
            ->assertFailed();

        $this->assertSame(0, Airport::count());
    }

    /** @param callable(array<int, array<string, mixed>>): array<int, array<string, mixed>> $change */
    private function rewriteStands(callable $change): void
    {
        $path = $this->dataPath.'/stands/KTST.geojson';
        $collection = json_decode((string) file_get_contents($path), true);
        $collection['features'] = $change($collection['features']);
        file_put_contents($path, json_encode($collection));
    }
}
