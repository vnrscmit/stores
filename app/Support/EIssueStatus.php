<?php

namespace App\Support;

/**
 * e-Issue lifecycle states. The legacy flags are preserved verbatim
 * (issuetrflag on issues); `status` adds a port-level gate so the posting
 * transaction cannot run twice:
 *
 *   OPEN (legacy issuetrflag=0) -> entries may be added/edited/removed
 *     -> POSTED (legacy issuetrflag=1): writes stock_ledger rows through
 *        StockLedgerService, assigns iss_code/ncode, closes the indent
 *        (e_indents.flg=1). Immutable afterwards.
 */
final class EIssueStatus
{
    public const OPEN = 'open';

    public const POSTED = 'posted';

    /** Labels for list/detail screens. */
    public const LABELS = [
        self::OPEN => 'Open (awaiting final post)',
        self::POSTED => 'Posted (stock updated)',
    ];

    private function __construct() {}
}
