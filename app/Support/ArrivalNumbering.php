<?php

namespace App\Support;

use App\Models\Arrival;
use App\Models\ItemTransfer;

/**
 * Arrivals-family numbering, continuing the legacy sequences. Legacy used
 * SELECT MAX()+1 per (yearcode, arrival_type) for both serials; the port
 * uses the race-safe document_counters, seeded from legacy MAX during
 * legacy:migrate-data with lazy priming from the data for fresh
 * databases/newly opened years (same pattern as IssueNumbering):
 *
 *  - arrival.vendor      -> arr_code (committed,  arrival_type 'Vendor')
 *  - arrival.vendor.n    -> ncode    (note serial)
 *  - arrival.stocktr     -> arr_code (arrival_type 'Stocktransfer')
 *  - arrival.stocktr.n   -> ncode
 *  - arrival.internal    -> arr_code (arrival_type 'Internalreturn')
 *  - arrival.internal.n  -> ncode
 *
 * Inter-item transfer (ITI/ITA) numbering: counter `iitr` ->
 * item_transfers.iitr_code. LEGACY DEVIATION (documented in PHASE9.md):
 * the legacy commit path assigned no code (the MAX(code) block was
 * commented out); the port assigns iitr_code from this counter so every
 * committed document is addressable.
 *
 * Entry-time workspace ids: legacy did not keep an entry serial on
 * tblarrival (the header is keyed by arrival_id while open), so the
 * workspace reuse model is arrival_id-based like the captive module — no
 * workspace counter here.
 */
class ArrivalNumbering
{
    /** type key => [counter, legacy arrival_type literal] */
    public const TYPES = [
        'vendor' => ['counter' => 'arrival.vendor', 'arrival_type' => 'Vendor'],
        'stocktr' => ['counter' => 'arrival.stocktr', 'arrival_type' => 'Stocktransfer'],
        'internal' => ['counter' => 'arrival.internal', 'arrival_type' => 'Internalreturn'],
    ];

    /**
     * Next committed serial (arr_code) for an arrival type. Must run inside
     * the posting transaction; bootstraps the counter from the data when
     * legacy:migrate-data has not seeded this type/year.
     */
    public static function primeCommitted(string $yearcode, string $type): int
    {
        $counter = self::counter($type);

        if (DocumentNumber::current($counter, $yearcode) === 0) {
            DocumentNumber::prime($counter, $yearcode,
                (int) Arrival::query()
                    ->where('arrival_type', self::arrivalType($type))
                    ->where('yearcode', $yearcode)
                    ->max('arr_code'));
        }

        return DocumentNumber::next($counter, $yearcode);
    }

    /**
     * Next note-print serial (ncode) for an arrival type — lazily primed
     * from MAX(ncode) per (yearcode, arrival_type), mirroring the issue
     * note counters.
     */
    public static function primeNote(string $yearcode, string $type): int
    {
        $counter = self::counter($type).'.n';

        if (DocumentNumber::current($counter, $yearcode) === 0) {
            DocumentNumber::prime($counter, $yearcode,
                (int) Arrival::query()
                    ->where('arrival_type', self::arrivalType($type))
                    ->where('yearcode', $yearcode)
                    ->max('ncode'));
        }

        return DocumentNumber::next($counter, $yearcode);
    }

    /**
     * Next committed serial (iitr_code) for the ITI/ITA family. Legacy
     * never assigned it (numbering gap — see PHASE9.md); the counter is
     * seeded from MAX(iitr_code) so any legacy-coded rows still continue.
     */
    public static function primeItemTransferCommitted(string $yearcode): int
    {
        if (DocumentNumber::current('iitr', $yearcode) === 0) {
            DocumentNumber::prime('iitr', $yearcode,
                (int) ItemTransfer::query()
                    ->where('yearcode', $yearcode)
                    ->max('iitr_code'));
        }

        return DocumentNumber::next('iitr', $yearcode);
    }

    /**
     * Next entry-time workspace id (legacy MAX(arrival_code)+1 per yearcode x
     * arrival_type, stamped on the header when the first line is saved).
     * Called inside the caller's transaction; lockForUpdate on the matching
     * rows prevents two workspaces from taking the same id.
     */
    public static function nextWorkspaceCode(string $yearcode, string $type): int
    {
        $max = Arrival::query()
            ->where('arrival_type', self::arrivalType($type))
            ->where('yearcode', $yearcode)
            ->lockForUpdate()
            ->max('arrival_code');

        return (int) $max + 1;
    }

    /**
     * Legacy transaction-id text for a header: TAV{arrival_code}/{yearcode}/{role}
     * while open, TAV{arr_code}/{yearcode}/{role} once posted (legacy preview
     * screen swaps the serial at commit).
     */
    public static function transactionId(Arrival $arrival, string $type): string
    {
        $code = ((int) $arrival->arrtrflag === 1 && $arrival->arr_code !== null)
            ? $arrival->arr_code
            : $arrival->arrival_code;

        return DocumentNumber::pretty(self::counter($type), (int) $code, (string) $arrival->yearcode);
    }

    /** Legacy committed display, e.g. TAV12/20222023. */
    public static function committedId(string $type, int $code, string $yearcode): string
    {
        return DocumentNumber::pretty(self::counter($type), $code, $yearcode);
    }

    private static function counter(string $type): string
    {
        if (! isset(self::TYPES[$type])) {
            throw new \InvalidArgumentException("Unknown arrival type [{$type}].");
        }

        return self::TYPES[$type]['counter'];
    }

    private static function arrivalType(string $type): string
    {
        return self::TYPES[$type]['arrival_type'];
    }
}
