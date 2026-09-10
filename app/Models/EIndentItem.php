<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Legacy source: tbl_ieindent_sub (storesd).
 * Column names and primary key preserved verbatim.
 */
class EIndentItem extends Model
{
    protected $table = 'e_indent_items';

    protected $primaryKey = 'eid';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $guarded = [];

    public function eIndent(): BelongsTo
    {
        return $this->belongsTo(EIndent::class, 'id_in', 'tid');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'items_id', 'items_id');
    }

    public function classification(): BelongsTo
    {
        return $this->belongsTo(Classification::class, 'classification_id', 'classification_id');
    }
}
