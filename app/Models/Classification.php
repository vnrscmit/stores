<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Legacy source: tbl_classification (storesd).
 * Column names and primary key preserved verbatim.
 */
class Classification extends Model
{
    protected $table = 'classifications';

    protected $primaryKey = 'classification_id';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $guarded = [];
}
