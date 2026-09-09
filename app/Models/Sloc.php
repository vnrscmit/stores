<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Legacy source: tbl_sloc (storesd).
 * Column names and primary key preserved verbatim.
 */
class Sloc extends Model
{
    protected $table = 'slocs';

    protected $primaryKey = 'slid';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $guarded = [];
}
