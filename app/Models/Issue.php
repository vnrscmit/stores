<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Legacy source: tblissue (storesd).
 * Column names and primary key preserved verbatim.
 */
class Issue extends Model
{
    protected $table = 'issues';

    protected $primaryKey = 'issue_id';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $guarded = [];
}
