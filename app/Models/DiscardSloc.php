<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Legacy source: tbl_discard_sloc (storesd).
 * Column names and primary key preserved verbatim.
 */
class DiscardSloc extends Model
{
    protected $table = 'discard_slocs';

    protected $primaryKey = 'discardsloc_id';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $guarded = [];

    public function discard(): BelongsTo
    {
        return $this->belongsTo(Discard::class, 'discard_trid', 'tid');
    }
}
