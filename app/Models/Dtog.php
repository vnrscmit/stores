<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Legacy source: tbl_dtog (storesd).
 * Column names and primary key preserved verbatim.
 */
class Dtog extends Model
{
    protected $table = 'dtogs';

    protected $primaryKey = 'did';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $guarded = [];
}
