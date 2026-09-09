<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Legacy source: tbl_subbin (storesd).
 * Column names and primary key preserved verbatim.
 */
class SubBin extends Model
{
    protected $table = 'sub_bins';

    protected $primaryKey = 'sid';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $guarded = [];

    public function bin(): BelongsTo
    {
        return $this->belongsTo(Bin::class, 'binid', 'binid');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'whid', 'whid');
    }
}
