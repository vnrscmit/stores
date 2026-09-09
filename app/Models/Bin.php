<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Legacy source: tbl_bin (storesd).
 * Column names and primary key preserved verbatim.
 */
class Bin extends Model
{
    protected $table = 'bins';

    protected $primaryKey = 'binid';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $guarded = [];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'whid', 'whid');
    }
}
