<?php

namespace App\Http\Controllers\Arrival;

use App\Http\Controllers\Controller;
use App\Models\StockLedgerGood;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;

/**
 * GET reference availability for a classification + item (legacy
 * getuser_arrv_sbinckv.php + the sloc-show panes): the latest balance per
 * SLOC for the item, shown while distributing a receipt. Receiving does not
 * draw down stock, so these rows are display references only — the
 * selected row id rides along as arrival_slocs.rowid (legacy parity).
 */
class ArrivalAvailabilityController extends Controller
{
    use AuthorizesRequests;

    public function availability(int $classification, int $item): JsonResponse
    {
        $this->authorize('post-transactions');

        $locations = StockLedgerGood::query()
            ->select('stlg_whid', 'stlg_binid', 'stlg_subbinid')
            ->where('stlg_tritemid', $item)
            ->where('stlg_trclassid', $classification)
            ->groupBy('stlg_whid', 'stlg_binid', 'stlg_subbinid')
            ->get()
            ->map(function ($loc) use ($item) {
                $latest = StockLedgerGood::query()
                    ->where('stlg_tritemid', $item)
                    ->where('stlg_whid', $loc->stlg_whid)
                    ->where('stlg_binid', $loc->stlg_binid)
                    ->where('stlg_subbinid', $loc->stlg_subbinid)
                    ->orderByDesc('stlg_id')
                    ->first();

                return [
                    'key' => $loc->stlg_whid.'-'.$loc->stlg_binid.'-'.$loc->stlg_subbinid,
                    'stlg_id' => $latest?->stlg_id,
                    'whid' => (int) $loc->stlg_whid,
                    'binid' => (int) $loc->stlg_binid,
                    'subbinid' => (int) $loc->stlg_subbinid,
                    'ups' => (int) ($latest->stlg_balups ?? 0),
                    'qty' => (float) ($latest->stlg_balqty ?? 0),
                ];
            })
            ->values();

        return response()->json(['ok' => true, 'availability' => $locations]);
    }
}
