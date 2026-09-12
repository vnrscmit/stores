<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Legacy source: tbl_captive (storesd).
 * Column names and primary key preserved verbatim.
 *
 * Port extension: `status` column (open|posted) mirrors ccflg with a
 * readable state (see EIssueStatus); used by the captive-consumption (CC)
 * transaction screen so the posting transaction cannot run twice.
 */
class Captive extends Model
{
    protected $table = 'captives';

    protected $primaryKey = 'tid';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $guarded = [];

    public function items(): HasMany
    {
        return $this->hasMany(CaptiveItem::class, 'id_in', 'tid');
    }

    public function isPosted(): bool
    {
        return (int) $this->ccflg === 1;
    }
}
