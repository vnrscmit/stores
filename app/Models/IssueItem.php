<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Legacy source: tblissue_sub (storesd).
 * Column names and primary key preserved verbatim.
 */
class IssueItem extends Model
{
    protected $table = 'issue_items';

    protected $primaryKey = 'issuesub_id';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $guarded = [];

    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class, 'issue_id', 'issue_id');
    }
}
