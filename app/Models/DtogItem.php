<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Legacy source: tbl_dtog_sub (storesd).
 * Column names and primary key preserved verbatim.
 */
class DtogItem extends Model
{
    protected $table = 'dtog_items';

    protected $primaryKey = 'dgsubid';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $guarded = [];

    public function dtog(): BelongsTo
    {
        return $this->belongsTo(Dtog::class, 'did', 'did');
    }
}
