<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Legacy source: tbl_issuestock (storesd).
 * Column names and primary key preserved verbatim.
 */
class IssueStockHeader extends Model
{
    protected $table = 'issue_stock_headers';

    protected $primaryKey = 'id';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $guarded = [];
}
