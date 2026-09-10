<?php

namespace App\Support;

/**
 * e-Indent lifecycle states. The legacy flags are preserved verbatim
 * (tflg/flg on e_indents); `status` extends the pipeline with an approval
 * gate between final submit and operator issuance:
 *
 *   DRAFT (tflg=0) -> PENDING (tflg=1, status=pending)
 *     -> APPROVED (admin)   -> operator issues against it (legacy flg=1 closes it)
 *     -> REJECTED (admin)   -> raiser reopens to DRAFT, edits, resubmits
 */
final class EIndentStatus
{
    public const DRAFT = 'draft';

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    /** Allowed transitions out of each state. */
    public const TRANSITIONS = [
        self::DRAFT => [self::PENDING],
        self::PENDING => [self::APPROVED, self::REJECTED],
        self::REJECTED => [self::DRAFT],
        self::APPROVED => [],
    ];

    /** Labels for list/detail screens. */
    public const LABELS = [
        self::DRAFT => 'Draft',
        self::PENDING => 'Pending approval',
        self::APPROVED => 'Approved (open for issue)',
        self::REJECTED => 'Rejected (returned to raiser)',
    ];

    /** Values accepted by the index filter. */
    public const FILTERS = [self::DRAFT, self::PENDING, self::APPROVED, self::REJECTED];

    private function __construct() {}
}
