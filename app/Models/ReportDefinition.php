<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Legacy source: tbl_report (storesd).
 * Column names and primary key preserved verbatim.
 */
class ReportDefinition extends Model
{
    protected $table = 'report_definitions';

    protected $primaryKey = 'id';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $guarded = [];
}
