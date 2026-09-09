<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Legacy source: tbl_stldg_good (storesd).
 * Column names and primary key preserved verbatim.
 */
class StockLedgerGood extends Model
{
    protected $table = 'stock_ledger_goods';

    protected $primaryKey = 'stlg_id';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $guarded = [];
}
