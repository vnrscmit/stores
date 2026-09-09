<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Legacy source: tbl_gtod_sub (storesd).
 * Column names and primary key preserved verbatim.
 */
class GtodItem extends Model
{
    protected $table = 'gtod_items';

    protected $primaryKey = 'gdsubid';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $guarded = [];

    public function gtod(): BelongsTo
    {
        return $this->belongsTo(Gtod::class, 'gid', 'gid');
    }
}
