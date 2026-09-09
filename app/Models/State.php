<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Legacy source: tbl_state (storesd).
 * Column names and primary key preserved verbatim.
 */
class State extends Model
{
    protected $table = 'states';

    protected $primaryKey = 'state_id';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $guarded = [];
}
