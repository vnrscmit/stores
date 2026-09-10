<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Legacy source: tbl_ieindent (storesd).
 * Column names and primary key preserved verbatim.
 *
 * Lifecycle (legacy flags):
 * - tflg: 0 = draft workspace, 1 = finally submitted.
 * - flg:  0 = awaiting issuance by the operator, 1 = closed.
 * Port extension: `status` adds an approval gate between submit and issue:
 * pending -> approved | rejected. See EIndentStatus.
 */
class EIndent extends Model
{
    protected $table = 'e_indents';

    protected $primaryKey = 'tid';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $guarded = [];

    public function raiser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id', 'id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(EIndentItem::class, 'id_in', 'tid');
    }

    /** Issues posted against this indent (via dcrefno = indent code). */
    public function issues(): HasMany
    {
        return $this->hasMany(Issue::class, 'dcrefno', 'code')
            ->where('issue_type', 'eindent');
    }
}
