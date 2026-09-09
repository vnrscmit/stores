<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Legacy source: tbl_captive (storesd).
 * Column names and primary key preserved verbatim.
 */
class Captive extends Model
{
    protected $table = 'captives';

    protected $primaryKey = 'tid';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $guarded = [];
}
