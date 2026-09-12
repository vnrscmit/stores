<?php

namespace App\Support;

/**
 * The three self-contained issue types sharing the `issues` table (legacy
 * tblissue): the header is created on first line post, lines carry their own
 * qty (there is no source document), and the final post writes ledger rows
 * with the type-specific trsubtype.
 *
 * Contract per type (legacy):
 *
 *  - pindent  Transaction/add_issu_physical_indent.php        -> TIP prefix
 *  - stocktr  add_issue_str_view.php / getuser_issue_strupdate -> TIS entry,
 *             committed display "IS{iss_code}/{yearcode}/{role}"
 *  - mrtv     add_issue_mrtv_view.php / getuser_issue_mrtvedtupdate -> IM prefix,
 *             committed display "IM{iss_code}/{yearcode}/{role}"
 *
 * The e-indent issue (issue_type='eindent') is the Phase-6 flow and is not
 * listed here — it consumes approved indents instead of free lines.
 */
final class IssueTypes
{
    public const PHYSICAL_INDENT = 'pindent';

    public const STOCK_TRANSFER = 'stocktr';

    public const MRTV = 'mrtv';

    /** @var array<string, array{label: string, entry_prefix: string, committed_prefix: string}> */
    public const META = [
        self::PHYSICAL_INDENT => [
            'label' => 'Issue against Physical Indent',
            'entry_prefix' => 'TIP',
            'committed_prefix' => 'TIP',
        ],
        self::STOCK_TRANSFER => [
            'label' => 'Issue — Stock Transfer',
            'entry_prefix' => 'TIS',
            'committed_prefix' => 'IS',
        ],
        self::MRTV => [
            'label' => 'Issue — Material Return to Vendor',
            'entry_prefix' => 'IM',
            'committed_prefix' => 'IM',
        ],
    ];

    public static function all(): array
    {
        return array_keys(self::META);
    }

    public static function label(string $type): string
    {
        return self::META[$type]['label'] ?? ucfirst($type);
    }

    private function __construct() {}
}
