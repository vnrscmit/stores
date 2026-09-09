<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Legacy source: tbl_excess_sub (storesd).
 * Column names and primary key preserved verbatim.
 */
class ExcessItem extends Model
{
    protected $table = 'excess_items';

    protected $primaryKey = 'essubid';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $guarded = [];

    public function excess(): BelongsTo
    {
        return $this->belongsTo(Excess::class, 'esid', 'tid');
    }
}
