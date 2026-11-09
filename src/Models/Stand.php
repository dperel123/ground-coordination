<?php

namespace Nyartcc\GroundCoordination\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Nyartcc\GroundCoordination\Database\Factories\StandFactory;

/**
 * @property int $id
 * @property int $airport_id
 * @property string $name
 * @property string|null $area
 * @property float $lat
 * @property float $lon
 * @property int|null $heading
 * @property int|null $radius_m
 * @property string $source
 * @property string $attribution
 * @property bool $active
 */
#[Table('gc_stands')]
#[UseFactory(StandFactory::class)]
class Stand extends Model
{
    /** @use HasFactory<StandFactory> */
    use HasFactory;

    protected $fillable = ['airport_id', 'name', 'area', 'lat', 'lon', 'heading', 'radius_m', 'source', 'attribution', 'active'];

    protected function casts(): array
    {
        return [
            'lat' => 'float',
            'lon' => 'float',
            'heading' => 'integer',
            'radius_m' => 'integer',
            'active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Airport, $this> */
    public function airport(): BelongsTo
    {
        return $this->belongsTo(Airport::class);
    }

    /** @param Builder<Stand> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('active', true);
    }

    /** The stand's own capture radius, or the airport's when the stand has none. */
    public function effectiveRadius(Airport $airport): int
    {
        return $this->radius_m ?? $airport->match_radius_m;
    }
}
