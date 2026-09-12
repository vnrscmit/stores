<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Legacy source: tbl_captivesub (storesd).
 * Column names and primary key preserved verbatim.
 */
class CaptiveItem extends Model
{
    protected $table = 'captive_items';

    protected $primaryKey = 'eid';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $guarded = [];

    public function slocs(): HasMany
    {
        return $this->hasMany(CaptiveSloc::class, 'isue_id', 'eid');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'items_id', 'items_id');
    }

    public function classification(): BelongsTo
    {
        return $this->belongsTo(Classification::class, 'classification_id', 'classification_id');
    }

    public function captive(): BelongsTo
    {
        return $this->belongsTo(Captive::class, 'id_in', 'tid');
    }
}
