<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Legacy source: tbl_ciupdation (storesd).
 * Column names and primary key preserved verbatim.
 */
class CycleCountUpdate extends Model
{
    protected $table = 'cycle_count_updates';

    protected $primaryKey = 'ciu_id';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $guarded = [];

    public function cycleCount(): BelongsTo
    {
        return $this->belongsTo(CycleCount::class, 'ci_id', 'ci_id');
    }
}
