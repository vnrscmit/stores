<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Legacy source: tbl_warehouse (storesd).
 * Column names and primary key preserved verbatim.
 */
class Warehouse extends Model
{
    protected $table = 'warehouses';

    protected $primaryKey = 'whid';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $guarded = [];

    public function bins(): HasMany
    {
        return $this->hasMany(Bin::class, 'whid', 'whid');
    }
}
