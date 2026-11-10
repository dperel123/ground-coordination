<?php

namespace Nyartcc\GroundCoordination\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The outcome of a traffic feed's latest fetch. Read and written through
 * Services\FeedStatus.
 *
 * @property int $id
 * @property string $feed
 * @property string $last_outcome ok | failed
 * @property Carbon $last_attempt_at
 * @property Carbon|null $last_success_at
 * @property Carbon|null $last_failure_at
 * @property string|null $last_error
 */
#[Table('gc_feed_status')]
class FeedState extends Model
{
    public const OK = 'ok';

    public const FAILED = 'failed';

    protected $fillable = ['feed', 'last_outcome', 'last_attempt_at', 'last_success_at', 'last_failure_at', 'last_error'];

    protected function casts(): array
    {
        return [
            'last_attempt_at' => 'datetime',
            'last_success_at' => 'datetime',
            'last_failure_at' => 'datetime',
        ];
    }
}
