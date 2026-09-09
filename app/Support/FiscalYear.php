<?php

namespace App\Support;

use App\Models\FinancialYear;
use Illuminate\Support\Facades\Session;

/**
 * Legacy session contract: on login the active financial year row
 * (tblyears where years_flg != 0 AND years_status = 'a') supplied
 * $_SESSION['ayear1'], ['ayear2'], ['yearid_id']. This class preserves that
 * contract for every authenticated request via the `fy` middleware.
 */
class FiscalYear
{
    private static ?FinancialYear $resolved = null;

    public static function resolve(): FinancialYear
    {
        if (self::$resolved !== null) {
            return self::$resolved;
        }

        $cached = Session::get('fy_id');
        if ($cached) {
            $fy = FinancialYear::query()->find($cached);
            if ($fy && (int) $fy->years_flg !== 0 && $fy->years_status === 'a') {
                return self::$resolved = $fy;
            }
        }

        $fy = FinancialYear::query()
            ->where('years_flg', '!=', 0)
            ->where('years_status', 'a')
            ->first();

        abort_if($fy === null, 500, 'No active financial year. Ask the administrator to set one (Masters → Year Setting).');

        Session::put('fy_id', $fy->getKey());

        return self::$resolved = $fy;
    }

    /** Legacy $yearid_id — the per-year document code (e.g. 20222023). */
    public static function yearcode(): string
    {
        return (string) self::resolve()->ycode;
    }

    /** Legacy $ayear1. */
    public static function year1(): int
    {
        return (int) self::resolve()->year1;
    }

    /** Legacy $ayear2. */
    public static function year2(): int
    {
        return (int) self::resolve()->year2;
    }

    /** Legacy $year — display name (e.g. 2022-2023). */
    public static function name(): string
    {
        return (string) self::resolve()->year_name;
    }

    /** Forget the cached resolution (used on login/logout/FY switch). */
    public static function flush(): void
    {
        self::$resolved = null;
        Session::forget('fy_id');
    }

    /**
     * Testing hook: the static in-process cache outlives database changes
     * inside a single PHP process (e.g. feature tests mutating financial_years).
     */
    public static function invalidateForTesting(): void
    {
        self::$resolved = null;
    }
}
