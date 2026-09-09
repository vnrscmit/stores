<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Legacy source: tbl_pindents (storesd).
 * Column names and primary key preserved verbatim.
 */
class Pindent extends Model
{
    protected $table = 'pindents';

    protected $primaryKey = 'id';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $guarded = [];
}
