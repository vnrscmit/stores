<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Legacy source: tbl_partymaser (storesd).
 * Column names and primary key preserved verbatim.
 */
class Party extends Model
{
    protected $table = 'parties';

    protected $primaryKey = 'p_id';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $guarded = [];
}
