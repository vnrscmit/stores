<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Legacy source: tbl_captive_sloc (storesd).
 * Column names and primary key preserved verbatim.
 */
class CaptiveSloc extends Model
{
    protected $table = 'captive_slocs';

    protected $primaryKey = 'issuesloc_id';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $guarded = [];
}
