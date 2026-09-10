<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Legacy source: tbl_stores (storesd).
 * Column names and primary key preserved verbatim.
 */
class Item extends Model
{
    protected $table = 'items';

    protected $primaryKey = 'items_id';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $guarded = [];

    public function classification(): BelongsTo
    {
        return $this->belongsTo(Classification::class, 'classification_id', 'classification_id');
    }

    public function reorderLevels(): HasMany
    {
        return $this->hasMany(ReorderLevel::class, 'items_id', 'items_id');
    }
}
