<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function slocs(): HasMany
    {
        return $this->hasMany(IssueSloc::class, 'issue_id', 'issuesub_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id', 'items_id');
    }
}
