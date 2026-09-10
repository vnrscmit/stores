<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Legacy source: tblissue_sloc (storesd).
 * Column names and primary key preserved verbatim.
 */
class IssueSloc extends Model
{
    protected $table = 'issue_slocs';

    protected $primaryKey = 'issuesloc_id';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $guarded = [];

    /** The issue-item line this stock-location entry belongs to. */
    public function issueItem(): BelongsTo
    {
        return $this->belongsTo(IssueItem::class, 'issue_id', 'issuesub_id');
    }
}
