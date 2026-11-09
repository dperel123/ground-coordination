<?php

namespace Nyartcc\GroundCoordination\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Nyartcc\GroundCoordination\Database\Factories\AirportFactory;

/**
 * @property int $id
 * @property string $icao
 * @property string $name
 * @property float $lat
 * @property float $lon
 * @property array{min_lat: float, min_lon: float, max_lat: float, max_lon: float} $bbox
 * @property string $timezone
 * @property int $match_radius_m
 * @property bool $active
 */
#[Table('gc_airports')]
#[UseFactory(AirportFactory::class)]
class Airport extends Model
{
    /** @use HasFactory<AirportFactory> */
    use HasFactory;

    protected $fillable = ['icao', 'name', 'lat', 'lon', 'bbox', 'timezone', 'match_radius_m', 'active'];

    protected function casts(): array
    {
        return [
            'lat' => 'float',
            'lon' => 'float',
            'bbox' => 'array',
            'match_radius_m' => 'integer',
            'active' => 'boolean',
        ];
    }

    /** @return HasMany<Stand, $this> */
    public function stands(): HasMany
    {
        return $this->hasMany(Stand::class);
    }
}
