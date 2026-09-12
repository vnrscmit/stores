<?php

namespace App\Support;

use App\Models\Issue;

/**
 * Issue numbering, continuing the legacy sequences. Legacy used
 * SELECT MAX()+1 per yearcode for all three serials; the port uses the
 * race-safe document_counters, seeded from legacy MAX during legacy:migrate-data:
 *
 *  - issue.eindent   -> iss_code (committed serial, assigned at post)
 *  - issue.eindent.n -> ncode     (note-print serial, assigned at post)
 *
 * Each self-contained type (pindent, stocktr, mrtv) keeps its own committed
 * and note sequences (legacy MAX(iss_code) and MAX(ncode) were scoped by
 * issue_type), via counters `issue.{type}` and `issue.{type}.n`.
 *
 * issue_code (the entry-time transaction id shown while the transaction is
 * open) follows the legacy pattern: MAX(issue_code)+1 within yearcode x
 * issue_type — it identifies an open workspace and is never seeded into a
 * counter.
 */
class IssueNumbering
{
    public const COMMITTED_COUNTER = 'issue.eindent';

    public const NOTE_COUNTER = 'issue.eindent.n';

    /** Legacy transaction-id text: TIE{issue_code}/{yearcode}/{login}. */
    public static function transactionId(Issue $issue): string
    {
        $prefix = $issue->issue_type === 'eindent'
            ? 'TIE'
            : IssueTypes::META[$issue->issue_type]['entry_prefix'] ?? 'TIE';

        return sprintf('%s%d/%s/%s', $prefix, $issue->issue_code, $issue->yearcode, $issue->issue_role);
    }

    /** Legacy committed display: TIE{iss_code}/{yearcode}/{login}. */
    public static function committedId(Issue $issue): string
    {
        $prefix = $issue->issue_type === 'eindent'
            ? 'TIE'
            : IssueTypes::META[$issue->issue_type]['committed_prefix'] ?? 'TIE';

        return sprintf('%s%d/%s/%s', $prefix, $issue->iss_code, $issue->yearcode, $issue->issue_role);
    }

    /**
     * Next entry-time workspace id (legacy MAX(issue_code)+1 per yearcode x
     * type). Called inside the caller's transaction; lockForUpdate on the
     * matching rows prevents two workspaces from taking the same id.
     */
    public static function nextWorkspaceCode(string $yearcode, string $type = 'eindent'): int
    {
        $max = Issue::query()
            ->where('issue_type', $type)
            ->where('yearcode', $yearcode)
            ->lockForUpdate()
            ->max('issue_code');

        return (int) $max + 1;
    }

    /**
     * Bootstrap a missing counter from the data itself, so numbering
     * continues seamlessly even when legacy:migrate-data has not seeded
     * this type/year (fresh test databases, newly opened financial years).
     */
    public static function primeCommitted(string $yearcode, string $type = 'eindent'): int
    {
        $counter = $type === 'eindent' ? self::COMMITTED_COUNTER : "issue.{$type}";

        if (DocumentNumber::current($counter, $yearcode) === 0) {
            DocumentNumber::prime($counter, $yearcode,
                (int) Issue::query()
                    ->where('issue_type', $type)
                    ->where('yearcode', $yearcode)
                    ->max('iss_code'));
        }

        return DocumentNumber::next($counter, $yearcode);
    }

    public static function primeNote(string $yearcode, string $type = 'eindent'): int
    {
        $counter = $type === 'eindent' ? self::NOTE_COUNTER : "issue.{$type}.n";

        if (DocumentNumber::current($counter, $yearcode) === 0) {
            DocumentNumber::prime($counter, $yearcode,
                (int) Issue::query()
                    ->where('issue_type', $type)
                    ->where('yearcode', $yearcode)
                    ->max('ncode'));
        }

        return DocumentNumber::next($counter, $yearcode);
    }
}
