<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Legacy source: tbl_iitr (storesd).
 * Column names and primary key preserved verbatim.
 */
class ItemTransfer extends Model
{
    protected $table = 'item_transfers';

    protected $primaryKey = 'iitr_id';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $guarded = [];
}
