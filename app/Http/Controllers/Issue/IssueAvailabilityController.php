<?php

namespace App\Http\Controllers\Issue;

use App\Http\Controllers\Controller;
use App\Models\StockLedgerGood;
use Illuminate\Http\JsonResponse;

/**
 * GET availability rows for a classification + item, shared by all four
 * movement-type entry screens (legacy getuser_issue_pindent_slocshow.php /
 * getuser_issue_str_etdrec.php / slocshowmrv family): latest positive
 * balance per SLOC for the item, so the distribution form can render.
 */
class IssueAvailabilityController extends Controller
{
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
            ->filter(fn ($l) => $l['qty'] > 0 || $l['ups'] > 0)
            ->values();

        return response()->json(['ok' => true, 'availability' => $locations]);
    }
}
