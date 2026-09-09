<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Legacy source: tbl_ireturn (storesd).
 * Column names and primary key preserved verbatim.
 */
class InternalReturn extends Model
{
    protected $table = 'internal_returns';

    protected $primaryKey = 'rid';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $guarded = [];
}
