<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Legacy source: tbl_sloc_sub (storesd).
 * Column names and primary key preserved verbatim.
 */
class SlocItem extends Model
{
    protected $table = 'sloc_items';

    protected $primaryKey = 'slocsubid';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $guarded = [];

    public function sloc(): BelongsTo
    {
        return $this->belongsTo(Sloc::class, 'slocid', 'slid');
    }
}
