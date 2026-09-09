<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Legacy source: tbl_discard (storesd).
 * Column names and primary key preserved verbatim.
 */
class Discard extends Model
{
    protected $table = 'discards';

    protected $primaryKey = 'tid';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $guarded = [];
}
