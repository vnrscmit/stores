<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Legacy source: tbl_ieindent (storesd).
 * Column names and primary key preserved verbatim.
 */
class EIndent extends Model
{
    protected $table = 'e_indents';

    protected $primaryKey = 'tid';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $guarded = [];
}
