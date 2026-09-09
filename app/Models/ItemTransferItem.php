<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Legacy source: tbl_iitr_sub (storesd).
 * Column names and primary key preserved verbatim.
 */
class ItemTransferItem extends Model
{
    protected $table = 'item_transfer_items';

    protected $primaryKey = 'iitrsub_id';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $guarded = [];

    public function itemTransfer(): BelongsTo
    {
        return $this->belongsTo(ItemTransfer::class, 'iitr_id', 'iitr_id');
    }
}
