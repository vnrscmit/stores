<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Legacy source: tblarrival_sub (storesd).
 * Column names and primary key preserved verbatim.
 *
 * The line carries the DC quantities (qty_per_dc/ups_per_dc), the good and
 * damage totals, the computed excess/shortage (exsh_qty/exsh_ups) and the
 * bin counts; each stock location received against the line is an
 * arrival_slocs row keyed by arr_id = arrsub_id.
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

    public function slocs(): HasMany
    {
        return $this->hasMany(ArrivalSloc::class, 'arr_id', 'arrsub_id');
    }
}
