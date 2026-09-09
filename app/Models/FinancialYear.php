<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Legacy source: tblyears (storesd).
 * Column names and primary key preserved verbatim.
 */
class FinancialYear extends Model
{
    protected $table = 'financial_years';

    protected $primaryKey = 'yearsid';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $guarded = [];
}
