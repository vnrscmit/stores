<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Legacy source: tblarrival_sub (storesd).
 * Column names and primary key preserved verbatim.
 */
class ArrivalItem extends Model
{
    protected $table = 'arrival_items';

    protected $primaryKey = 'arrsub_id';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $guarded = [];

    public function arrival(): BelongsTo
    {
        return $this->belongsTo(Arrival::class, 'arrival_id', 'arrival_id');
    }
}
