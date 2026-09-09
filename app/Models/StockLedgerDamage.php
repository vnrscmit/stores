<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Legacy source: tbl_stldg_damage (storesd).
 * Column names and primary key preserved verbatim.
 */
class StockLedgerDamage extends Model
{
    protected $table = 'stock_ledger_damages';

    protected $primaryKey = 'stld_id';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $guarded = [];
}
