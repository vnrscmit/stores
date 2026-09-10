<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function items(): HasMany
    {
        return $this->hasMany(Item::class, 'classification_id', 'classification_id');
    }
}
