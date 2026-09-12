<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function captives(): HasMany
    {
        return $this->hasMany(Captive::class, 'tid', 'stlg_trid');
    }

    public function issues(): HasMany
    {
        return $this->hasMany(Issue::class, 'issue_id', 'stlg_trid');
    }

    public function slocRows(): HasMany
    {
        return $this->hasMany(IssueSloc::class, 'issue_rowid', 'stlg_id');
    }

    public function captiveSlocRows(): HasMany
    {
        return $this->hasMany(CaptiveSloc::class, 'issue_rowid_', 'stlg_id');
    }
}
