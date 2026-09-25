<?php

namespace App\Support;

use App\Models\QrCode;
use Illuminate\Support\Facades\DB;

/**
 * QR code text construction — the port of the legacy generator's prefix
 * + serial logic (Transaction/generate_qr_codes.php):
 *
 *   qr_text = plantcode + yearcode(4d) + type_code(2d) + serial(5d)
 *   e.g.    D2526 11 00001 -> "D25261100001"
 *
 * Verbatim rules:
 *  - plantcode from company_settings row id=41, default 'DEF' (the live
 *    legacy table has no plantcode column, so legacy always used the
 *    default too);
 *  - yearcode = the active financial year with '-' stripped;
 *  - type_code from the classification's type (Roll=11 default,
 *    Pouch(es)=12, Sticker(s)=13 — legacy default 11, its live
 *    classifications table had no classification_type column);
 *  - the serial continues GLOBALLY per year+type (NOT per item), from
 *    MAX(CAST(RIGHT(qr_code_text, 5) AS UNSIGNED)) over codes sharing
 *    the prefix.
 *
 * Deliberate deviation: the legacy MAX() lookup raced (two popups could
 * mint the same serial); here the prefix's last serial is allocated
 * inside the caller's transaction with lockForUpdate on the
 * document_counters row (counter key `qr.{year}{type}`), preserving the
 * visible format and continuation behaviour.
 */
class QrSerial
{
    /** The type code for a classification (verbatim legacy mapping). */
    public static function typeCodeFor(?string $classificationType): int
    {
        return match (trim((string) $classificationType)) {
            'Pouch', 'Pouches' => 12,
            'Sticker', 'Stickers' => 13,
            default => 11, // Roll and every unset/unknown type
        };
    }

    /** The plant code (verbatim legacy source + default). */
    public static function plantCode(): string
    {
        $plant = DB::table('company_settings')
            ->where('id', 41)
            ->value('plant');

        $plant = is_string($plant) ? trim($plant) : '';

        return $plant !== '' ? $plant : 'DEF';
    }

    /** The prefix for a year/type, e.g. D252611 (D + 2526 + 11). */
    public static function prefix(string $yearcode, int $typeCode, ?string $plant = null): string
    {
        return ($plant ?? self::plantCode()).str_replace('-', '', $yearcode).sprintf('%02d', $typeCode);
    }

    /**
     * Allocate the next serial for a year/type. MUST run inside the
     * caller's DB transaction (lockForUpdate on the counter row). The
     * counter row lives in document_counters under the key
     * `qr.{year4}{type2}` / the active yearcode.
     */
    public static function nextSerial(string $yearcode, int $typeCode): int
    {
        $key = 'qr.'.str_replace('-', '', $yearcode).sprintf('%02d', $typeCode);

        $locked = DB::table('document_counters')
            ->where('doc_type', $key)
            ->where('yearcode', $yearcode)
            ->lockForUpdate()
            ->first();

        $last = 0;

        if ($locked !== null) {
            $last = (int) $locked->current_value;
        } else {
            // First allocation of this prefix: continue the legacy series
            // (MAX over the stored codes' 5-digit serial suffix).
            $last = (int) (QrCode::query()
                ->where('qr_code_text', 'like', self::prefix($yearcode, $typeCode).'%')
                ->selectRaw('MAX(CAST(RIGHT(qr_code_text, 5) AS UNSIGNED)) as last')
                ->value('last') ?? 0);
        }

        $next = $last + 1;

        DB::table('document_counters')->updateOrInsert(
            ['doc_type' => $key, 'yearcode' => $yearcode],
            ['current_value' => $next, 'created_at' => now(), 'updated_at' => now()]
        );

        return $next;
    }

    /**
     * Validate the client's displayed serial start and consume the whole
     * batch (count serials) for a year/type. MUST run inside the caller's
     * DB transaction. Aborts when the series moved since the page loaded
     * (the legacy MAX-requery race, made loud instead of silent).
     */
    public static function consumeBatch(string $yearcode, int $typeCode, int $serialStart, int $count): void
    {
        $expected = self::nextSerial($yearcode, $typeCode);

        abort_unless($expected === $serialStart, 409,
            'The QR serial series moved while this page was open. Reload and generate again.');

        if ($count > 1) {
            $key = 'qr.'.str_replace('-', '', $yearcode).sprintf('%02d', $typeCode);

            DB::table('document_counters')->updateOrInsert(
                ['doc_type' => $key, 'yearcode' => $yearcode],
                ['current_value' => $serialStart + $count - 1, 'created_at' => now(), 'updated_at' => now()]
            );
        }
    }

    /**
     * The next serial WITHOUT allocating it (read-only display value for
     * the generator form). Consuming happens in nextSerial() at save
     * time; a mismatch between the two aborts the save (the legacy
     * prefix-requery race, made loud instead of silent).
     */
    public static function peekSerial(string $yearcode, int $typeCode): int
    {
        $key = 'qr.'.str_replace('-', '', $yearcode).sprintf('%02d', $typeCode);

        $counter = DB::table('document_counters')
            ->where('doc_type', $key)
            ->where('yearcode', $yearcode)
            ->value('current_value');

        if ($counter !== null) {
            return (int) $counter + 1;
        }

        return (int) (QrCode::query()
            ->where('qr_code_text', 'like', self::prefix($yearcode, $typeCode).'%')
            ->selectRaw('MAX(CAST(RIGHT(qr_code_text, 5) AS UNSIGNED)) as last')
            ->value('last') ?? 0) + 1;
    }

    /** The zero-padded 5-digit serial (verbatim legacy format). */
    public static function padSerial(int $serial): string
    {
        return sprintf('%05d', $serial);
    }
}
