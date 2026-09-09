<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
}
