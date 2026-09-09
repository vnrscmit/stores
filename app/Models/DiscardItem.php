<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Legacy source: tbl_discard_sub (storesd).
 * Column names and primary key preserved verbatim.
 */
class DiscardItem extends Model
{
    protected $table = 'discard_items';

    protected $primaryKey = 'did';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $guarded = [];
}
