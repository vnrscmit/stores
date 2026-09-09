<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Legacy source: tbl_party_ldg (storesd).
 * Column names and primary key preserved verbatim.
 */
class PartyLedger extends Model
{
    protected $table = 'party_ledgers';

    protected $primaryKey = 'pldg_id';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $guarded = [];
}
