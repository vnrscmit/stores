<?php

namespace App\Support;

/**
 * The three inbound arrival types sharing the `arrivals` table (legacy
 * tblarrival): vendor GRN, stock transfer in and internal return. All three
 * post through StockLedgerService (direction 'in', trtype 'Arrival',
 * trsubtype = the type's legacy arrival subtype) and keep their own
 * committed/note serials (counters per arrival_type, see ArrivalNumbering).
 *
 * Contract per type (legacy sources):
 *
 *  - vendor    add_arrival_vendor.php + getuser_vupdateform.php +
 *              add_arrival_vendor_preview.php     -> TAV prefix, party-ledger
 *              row per item with the DC/excess/shortage block
 *  - stocktr   add_arrival_stocktransfer.php + getuser_stupdateform.php +
 *              add_arrival_stocktr_preview.php    -> TAS prefix, stnno header,
 *              no party ledger, no DC quantities
 *  - internal  add_return_stores.php + getuser_imroupdateform.php +
 *              add_return_stores_preview.php      -> TAI prefix, stageret/
 *              retid header, no party ledger
 */
final class ArrivalTypes
{
    public const VENDOR = 'vendor';

    public const STOCK_TRANSFER = 'stocktr';

    public const INTERNAL_RETURN = 'internal';

    /** @var array<string, array{label: string, arrival_type: string, entry_prefix: string}> */
    public const META = [
        self::VENDOR => [
            'label' => 'Arrival from Vendor',
            'arrival_type' => 'Vendor',
            'entry_prefix' => 'TAV',
        ],
        self::STOCK_TRANSFER => [
            'label' => 'Stock Transfer In',
            'arrival_type' => 'Stocktransfer',
            'entry_prefix' => 'TAS',
        ],
        self::INTERNAL_RETURN => [
            'label' => 'Internal Return to Stores',
            'arrival_type' => 'Internalreturn',
            'entry_prefix' => 'TAI',
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

    /** Legacy trsubtype written into the stock ledgers for a type. */
    public static function trsubtype(string $type): string
    {
        return self::META[$type]['arrival_type'];
    }

    /** Audit module key, mirroring the issue modules (issue.{type}). */
    public static function auditModule(string $type): string
    {
        return 'arrival.'.$type;
    }

    /** Types that write a party-ledger row per item at post time (vendor only). */
    public static function hasPartyLedger(string $type): bool
    {
        return $type === self::VENDOR;
    }

    /**
     * Types whose entry form carries the DC (delivery challan) block: only
     * the vendor GRN. Drives validation, the excess/shortage math and the
     * party-ledger DC columns (all 0 for the other two types, as in legacy).
     */
    public static function hasDcBlock(string $type): bool
    {
        return $type === self::VENDOR;
    }

    private function __construct() {}
}
