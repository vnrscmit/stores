<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Legacy source: tbl_country (storesd).
 * Column names and primary key preserved verbatim.
 */
class Country extends Model
{
    protected $table = 'countries';

    protected $primaryKey = 'country_id';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $guarded = [];
}
