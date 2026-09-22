<?php

namespace App\Models;

use App\Support\ArrivalStatus;
use App\Support\ArrivalTypes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Legacy source: tblarrival (storesd).
 * Column names and primary key preserved verbatim.
 *
 * Port extension: `status` column (open|posted) mirrors arrtrflag with a
 * readable state; see ArrivalStatus. The inbound arrival types (vendor,
 * stock transfer, internal return) share this header; lines live in
 * arrival_items and their stock locations in arrival_slocs.
 */
class Arrival extends Model
{
    protected $table = 'arrivals';

    protected $primaryKey = 'arrival_id';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $guarded = [];

    public function items(): HasMany
    {
        return $this->hasMany(ArrivalItem::class, 'arrival_id', 'arrival_id');
    }

    public function isPosted(): bool
    {
        return $this->status === ArrivalStatus::POSTED;
    }

    /** Title-case display for the arrival_type column spelling. */
    public function typeLabel(): string
    {
        foreach (ArrivalTypes::META as $meta) {
            if (strcasecmp($meta['arrival_type'], (string) $this->arrival_type) === 0) {
                return $meta['label'];
            }
        }

        return (string) $this->arrival_type;
    }
}
