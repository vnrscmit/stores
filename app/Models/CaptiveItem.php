<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public function captive(): BelongsTo
    {
        return $this->belongsTo(Captive::class, 'id_in', 'tid');
    }
}
