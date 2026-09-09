<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Legacy source: tbl_stock (storesd).
 * Column names and primary key preserved verbatim.
 */
class StockHeader extends Model
{
    protected $table = 'stock_headers';

    protected $primaryKey = 'sid';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $guarded = [];
}
