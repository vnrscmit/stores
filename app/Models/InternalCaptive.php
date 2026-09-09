<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Legacy source: tbl_icaptive (storesd).
 * Column names and primary key preserved verbatim.
 */
class InternalCaptive extends Model
{
    protected $table = 'internal_captives';

    protected $primaryKey = 'id';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $guarded = [];
}
