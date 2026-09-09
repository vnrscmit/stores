<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Legacy source: tbl_gtod (storesd).
 * Column names and primary key preserved verbatim.
 */
class Gtod extends Model
{
    protected $table = 'gtods';

    protected $primaryKey = 'gid';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $guarded = [];
}
