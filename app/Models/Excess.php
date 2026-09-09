<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Legacy source: tbl_excess (storesd).
 * Column names and primary key preserved verbatim.
 */
class Excess extends Model
{
    protected $table = 'excesses';

    protected $primaryKey = 'tid';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $guarded = [];
}
