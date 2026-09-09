<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Legacy source: tblarr_sloc (storesd).
 * Column names and primary key preserved verbatim.
 */
class ArrivalSloc extends Model
{
    protected $table = 'arrival_slocs';

    protected $primaryKey = 'arrsloc_id';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $guarded = [];
}
