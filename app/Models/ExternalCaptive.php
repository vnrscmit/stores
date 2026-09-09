<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Legacy source: tbl_ecaptive (storesd).
 * Column names and primary key preserved verbatim.
 */
class ExternalCaptive extends Model
{
    protected $table = 'external_captives';

    protected $primaryKey = 'cid';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $guarded = [];

    public function captive(): BelongsTo
    {
        return $this->belongsTo(Captive::class, 'tid', 'tid');
    }
}
