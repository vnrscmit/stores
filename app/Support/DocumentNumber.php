<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Per-year document numbering. Legacy used SELECT MAX(code)+1 which races
 * under concurrency; here a document_counters row is locked FOR UPDATE and
 * incremented atomically. Counters are seeded from legacy MAX values during
 * legacy:migrate-data so numbering continues seamlessly.
 *
 * Pretty prefixes (rendered at view time, never stored as strings):
 *   eindent draft/commit -> TIR, issue eindent -> TIE, stock transfer -> STRN…
 */
class DocumentNumber
{
    /** @var array<string, string> */
    public const PREFIXES = [
        'eindent' => 'TIR',
        'issue.eindent' => 'TIE',
        'issue.pindent' => 'TIP',
        'issue.stocktr' => 'TIS',
        'issue.internalcc' => 'TIC',
        'issue.mrtv' => 'TIM',
        'arrival.vendor' => 'TAV',
        'arrival.stocktr' => 'TAS',
        'arrival.internal' => 'TAI',
        'captive.vendor' => 'TCC',
        'discard' => 'TDD',
        'excess' => 'TES',
        'sloc' => 'TSL',
        'iitr' => 'TIIT',
        'dtog' => 'TDG',
        'gtod' => 'TGD',
        'gatepass' => 'GP',
    ];

    /**
     * Reserve the next number for a type/year. Must be called inside a
     * transaction (or will open its own).
     */
    public static function next(string $docType, string $yearcode): int
    {
        $inTransaction = DB::transactionLevel() > 0;

        $run = function () use ($docType, $yearcode): int {
            DB::table('document_counters')
                ->where('doc_type', $docType)
                ->where('yearcode', $yearcode)
                ->lockForUpdate()
                ->first();

            $updated = DB::table('document_counters')
                ->where('doc_type', $docType)
                ->where('yearcode', $yearcode)
                ->update(['current_value' => DB::raw('current_value + 1')]);

            if ($updated === 0) {
                DB::table('document_counters')->insert([
                    'doc_type' => $docType,
                    'yearcode' => $yearcode,
                    'current_value' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return 1;
            }

            return (int) DB::table('document_counters')
                ->where('doc_type', $docType)
                ->where('yearcode', $yearcode)
                ->value('current_value');
        };

        return $inTransaction ? $run() : DB::transaction($run);
    }

    /** Peek the current value without consuming (for parity checks). */
    public static function current(string $docType, string $yearcode): int
    {
        return (int) DB::table('document_counters')
            ->where('doc_type', $docType)
            ->where('yearcode', $yearcode)
            ->value('current_value');
    }

    /**
     * Ensure a counter exists at least at $value (bootstraps from the legacy
     * table MAX when the pipeline has not seeded this type/year yet).
     */
    public static function prime(string $docType, string $yearcode, int $value): void
    {
        DB::table('document_counters')->updateOrInsert(
            ['doc_type' => $docType, 'yearcode' => $yearcode],
            ['current_value' => $value, 'created_at' => now(), 'updated_at' => now()]
        );
    }

    /** Legacy pretty reference, e.g. TIE12/20222023. */
    public static function pretty(string $docType, int|string $code, string $yearcode): string
    {
        $prefix = self::PREFIXES[$docType] ?? strtoupper(substr($docType, 0, 3));

        return "{$prefix}{$code}/{$yearcode}";
    }
}
