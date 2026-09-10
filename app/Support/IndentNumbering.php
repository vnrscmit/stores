<?php

namespace App\Support;

use App\Models\EIndent;

/**
 * e-Indent document numbering on the shared DocumentNumber counters
 * (replacing the legacy MAX()+1 sequences from add_indents.php /
 * add_indents_preview.php, which raced under concurrency):
 *
 * - 'eindent.draft' → code1, assigned when the DRAFT workspace is first
 *   posted (rendered "T{code1}"; the transaction id renders
 *   "TIR{code1}/{yearcode}/{login}"). Seeded from legacy MAX(code1).
 * - 'eindent'       → code, assigned once at FINAL SUBMIT (the committed
 *   serial, rendered "IR{code}"). A committed indent keeps its code for
 *   life; reopen/resubmit cycles reuse it (legacy preview re-bumped it on
 *   every resubmit, leaving gaps). Seeded from legacy MAX(code).
 */
class IndentNumbering
{
    public const DRAFT_COUNTER = 'eindent.draft';

    public const COMMITTED_COUNTER = 'eindent';

    /** Draft serial (legacy code1) for the active year. */
    public static function nextDraftCode(string $yearcode): int
    {
        self::primeFromLegacy(self::DRAFT_COUNTER, $yearcode, 'code1');

        return DocumentNumber::next(self::DRAFT_COUNTER, $yearcode);
    }

    /**
     * Committed serial (legacy code) for the active year. Only consumed at
     * final submit.
     */
    public static function nextCommittedCode(string $yearcode): int
    {
        self::primeFromLegacy(self::COMMITTED_COUNTER, $yearcode, 'code');

        return DocumentNumber::next(self::COMMITTED_COUNTER, $yearcode);
    }

    /** Legacy transaction-id text: TIR{code1}/{yearcode}/{login}. */
    public static function transactionId(EIndent $indent): string
    {
        return sprintf('TIR%d/%s/%s', $indent->code1, $indent->yearcode, $indent->id);
    }

    /** Legacy committed display: IR{code} (home page link). */
    public static function committedId(EIndent $indent): string
    {
        return sprintf('IR%d', $indent->code);
    }

    /**
     * Bootstrap a missing counter from the data itself, so numbering
     * continues seamlessly even when legacy:migrate-data has not seeded
     * this type/year (fresh test databases, newly opened financial years).
     */
    private static function primeFromLegacy(string $docType, string $yearcode, string $column): void
    {
        if (DocumentNumber::current($docType, $yearcode) > 0) {
            return;
        }

        DocumentNumber::prime($docType, $yearcode,
            (int) EIndent::query()->where('yearcode', $yearcode)->max($column));
    }
}
