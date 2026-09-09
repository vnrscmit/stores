<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Legacy source: tbl_gate (storesd).
 * Column names and primary key preserved verbatim.
 */
class GatePass extends Model
{
    protected $table = 'gate_passes';

    protected $primaryKey = 'gpid';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $guarded = [];
}
