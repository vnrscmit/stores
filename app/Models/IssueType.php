<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Plain ORM wrapper for `issue_types` (legacy issue_type spellings with a
 * title-case display label). Not consumed by the controllers (IssueTypes in
 * App\Support is the registry); provided so the morph map alias does not
 * clash with an unbound class name.
 */
class IssueType extends Model
{
    public $timestamps = false;

    protected $guarded = [];
}
