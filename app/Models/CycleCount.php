<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Legacy source: tbl_ci (storesd).
 * Column names and primary key preserved verbatim.
 */
class CycleCount extends Model
{
    protected $table = 'cycle_counts';

    protected $primaryKey = 'ci_id';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $guarded = [];
}
