<?php

namespace Nyartcc\GroundCoordination\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Nyartcc\GroundCoordination\Database\Factories\GroundAircraftFactory;
use Nyartcc\GroundCoordination\Enums\GroundPhase;

/**
 * One aircraft on the ground at an airport, as the sweep last saw it.
 *
 * @property int $id
 * @property int $airport_id
 * @property string $callsign
 * @property int $cid
 * @property float $lat
 * @property float $lon
 * @property int $groundspeed
 * @property int $heading
 * @property int $altitude
 * @property string|null $aircraft_type
 * @property string|null $departure
 * @property string|null $arrival
 * @property int|null $stand_id
 * @property float|null $stand_distance_m
 * @property GroundPhase $phase
 * @property Carbon $first_seen_at
 * @property Carbon $last_seen_at
 * @property Carbon|null $stand_entered_at
 * @property Carbon|null $stand_left_at
 */
#[Table('gc_ground_aircraft')]
#[UseFactory(GroundAircraftFactory::class)]
class GroundAircraft extends Model
{
    /** @use HasFactory<GroundAircraftFactory> */
    use HasFactory;

    protected $fillable = [
        'airport_id', 'callsign', 'cid', 'lat', 'lon', 'groundspeed', 'heading', 'altitude',
        'aircraft_type', 'departure', 'arrival', 'stand_id', 'stand_distance_m', 'phase',
        'first_seen_at', 'last_seen_at', 'stand_entered_at', 'stand_left_at',
    ];

    protected function casts(): array
    {
        return [
            'cid' => 'integer',
            'lat' => 'float',
            'lon' => 'float',
            'groundspeed' => 'integer',
            'heading' => 'integer',
            'altitude' => 'integer',
            'stand_distance_m' => 'float',
            'phase' => GroundPhase::class,
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'stand_entered_at' => 'datetime',
            'stand_left_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Airport, $this> */
    public function airport(): BelongsTo
    {
        return $this->belongsTo(Airport::class);
    }

    /** @return BelongsTo<Stand, $this> */
    public function stand(): BelongsTo
    {
        return $this->belongsTo(Stand::class);
    }
}
