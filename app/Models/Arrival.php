<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Legacy source: tblarrival (storesd).
 * Column names and primary key preserved verbatim.
 */
class Arrival extends Model
{
    protected $table = 'arrivals';

    protected $primaryKey = 'arrival_id';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $guarded = [];
}
