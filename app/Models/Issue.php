<?php

namespace App\Models;

use App\Support\EIssueStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Legacy source: tblissue (storesd).
 * Column names and primary key preserved verbatim.
 *
 * Port extension: `status` column (open|posted) mirrors issuetrflag with a
 * readable state; see EIssueStatus. For issue_type='eindent' the issue
 * consumes approved e-Indents.
 */
class Issue extends Model
{
    protected $table = 'issues';

    protected $primaryKey = 'issue_id';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $guarded = [];

    /** Legacy label (issue_code) while open; committed serial (iss_code) after post. */
    public function transactionId(): string
    {
        return $this->issuetrflag == 1 && $this->iss_code !== null
            ? sprintf('TIE%d/%s/%s', $this->iss_code, $this->yearcode, $this->issue_role)
            : sprintf('TIE%d/%s/%s', $this->issue_code, $this->yearcode, $this->issue_role);
    }

    public function items(): HasMany
    {
        return $this->hasMany(IssueItem::class, 'issue_id', 'issue_id');
    }

    public function isPosted(): bool
    {
        return $this->status === EIssueStatus::POSTED;
    }
}
