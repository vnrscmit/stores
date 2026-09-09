<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Legacy source: tbl_eindents (storesd).
 * Column names and primary key preserved verbatim.
 */
class EindentRegister extends Model
{
    protected $table = 'eindent_registers';

    protected $primaryKey = 'tid';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $guarded = [];
}
