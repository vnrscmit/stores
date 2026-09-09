<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Legacy source: tbl_parameters (storesd).
 * Column names and primary key preserved verbatim.
 */
class CompanySetting extends Model
{
    protected $table = 'company_settings';

    protected $primaryKey = 'id';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $guarded = [];
}
