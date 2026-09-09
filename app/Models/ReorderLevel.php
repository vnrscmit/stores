<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Legacy source: tbl_order (storesd).
 * Column names and primary key preserved verbatim.
 */
class ReorderLevel extends Model
{
    protected $table = 'reorder_levels';

    protected $primaryKey = 'orderid';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $guarded = [];
}
