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
}
