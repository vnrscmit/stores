<?php

namespace App\Support;

/**
 * Arrival lifecycle states (Phase 9 slice 1 migration `000053`). The legacy
 * flags are preserved verbatim (arrtrflag on tblarrival); `status` adds a
 * port-level gate so the posting transaction cannot run twice:
 *
 *   OPEN (legacy arrtrflag=0) -> lines/slocs may be added/edited/removed
 *     -> POSTED (legacy arrtrflag=1): writes stock_ledger_good/damage rows
 *        through StockLedgerService, the vendor party-ledger row, assigns
 *        arr_code/ncode. Immutable afterwards.
 */
final class ArrivalStatus
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
