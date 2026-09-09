<?php

namespace App\Support;

use App\Models\StockLedgerDamage;
use App\Models\StockLedgerGood;
use App\Models\SubBin;
use Illuminate\Support\Facades\DB;

/**
 * The single writer for stock_ledger_goods / stock_ledger_damages.
 *
 * Ported 1:1 from Transaction/add_issue_eindents_preview.php,
 * add_arrival_vendor_preview.php and siblings. Rules preserved:
 *
 *  1. Opening balance = stlg_balups/stlg_balqty of the latest (MAX pk) row
 *     for item x warehouse x bin x sub-bin; zero when none exists.
 *  2. Direction: Issue/CC/DG/ES(SH)/SUC/IT-out subtract; Arrival/GD/ES/IT-in add.
 *  3. UPS normalization: balqty > 0 && balups == 0 -> balups = 1;
 *     balqty == 0 -> balups = 0.
 *  4. Out + balqty == 0 -> sub-bin status='Empty' (legacy cross-item check
 *     contained an undefined variable ($totnog) so the empty flag is applied
 *     whenever the moved item's balance hits zero — preserved deliberately).
 *  5. In -> sub-bin status='Good' (damaged receipts -> 'Damage').
 *  6. Reorder flag: srl-tracked item whose summed positive balance <= srl
 *     gets orstatus='R' on all positive rows (legacy `stlg_tritemid != item`
 *     filter preserved literally — see applyReorderFlag parity note).
 *
 * All writes execute inside the caller's DB::transaction().
 */
class StockLedgerService
{
    /**
     * Post one movement line. $p['direction'] is 'out' (issue family) or 'in'
     * (arrival family). Returns the created ledger row.
     */
    public static function post(array $p): StockLedgerGood|StockLedgerDamage
    {
        $damage = (bool) ($p['damage'] ?? false);

        $latest = self::latestRow((int) $p['item_id'], (int) $p['whid'], (int) $p['binid'], (int) $p['subbinid'], $damage);

        $opups = (int) ($latest->stlg_balups ?? 0);
        $opqty = (float) ($latest->stlg_balqty ?? 0);

        $trqty = (float) $p['qty'];
        $trups = (int) $p['ups'];

        $balqty = $p['direction'] === 'out' ? $opqty - $trqty : $opqty + $trqty;
        $balups = $p['direction'] === 'out' ? $opups - $trups : $opups + $trups;

        // Legacy UPS normalization (stlg_* columns are UNSIGNED in legacy).
        if ($balqty > 0 && $balups == 0) {
            $balups = 1;
        }
        if ($balqty == 0) {
            $balups = 0;
        }

        $row = $damage ? new StockLedgerDamage : new StockLedgerGood;

        // Damage ledger columns carry the stld_ prefix (stld_trtype, ...).
        $c = $damage ? 'stld' : 'stlg';

        $row->yearcode = $p['yearcode'];
        $row->{"{$c}_trtype"} = $p['trtype'];
        $row->{"{$c}_trsubtype"} = $p['trsubtype'];
        $row->{"{$c}_trid"} = $p['trid'];
        $row->{"{$c}_trpartyid"} = (string) ($p['partyid'] ?? 0);
        $row->{"{$c}_trdate"} = $p['trdate'];
        $row->{"{$c}_trclassid"} = $p['classid'];
        $row->{"{$c}_tritemid"} = $p['item_id'];
        $row->{"{$c}_whid"} = $p['whid'];
        $row->{"{$c}_binid"} = $p['binid'];
        $row->{"{$c}_subbinid"} = $p['subbinid'];
        $row->{"{$c}_opups"} = max(0, $opups);
        $row->{"{$c}_opqty"} = $opqty;
        $row->{"{$c}_trups"} = $trups;
        $row->{"{$c}_trqty"} = $trqty;
        $row->{"{$c}_balups"} = max(0, $balups);
        $row->{"{$c}_balqty"} = $balqty;
        $row->save();

        // Sub-bin status flip (legacy semantics).
        if ($p['direction'] === 'in') {
            SubBin::query()->where('sid', $p['subbinid'])->update([
                'status' => $damage ? 'Damage' : 'Good',
            ]);
        } elseif ($balqty == 0) {
            SubBin::query()->where('sid', $p['subbinid'])->update(['status' => 'Empty']);
        }

        return $row;
    }

    /**
     * Latest ledger row for an item at a location (good or damage ledger).
     * lockForUpdate serializes concurrent posts against the same bin cell.
     */
    public static function latestRow(int $itemId, int $whid, int $binid, int $subbinid, bool $damage = false): ?object
    {
        $model = $damage ? new StockLedgerDamage : new StockLedgerGood;

        // Damage ledger uses the stld_* prefix (stld_id, stld_tritemid, ...).
        $c = $damage ? 'stld' : 'stlg';

        return $model->newQuery()
            ->where("{$c}_tritemid", $itemId)
            ->where("{$c}_whid", $whid)
            ->where("{$c}_binid", $binid)
            ->where("{$c}_subbinid", $subbinid)
            ->orderByDesc("{$c}_id")
            ->lockForUpdate()
            ->first();
    }

    /**
     * Reorder flag port (legacy issue writer): when a srl-tracked item's
     * summed positive balance drops to/below its reorder level, all of its
     * positive rows are flagged orstatus='R'.
     *
     * PARITY NOTE: the legacy DISTINCT-location query filters
     * `stlg_tritemid != item` before re-checking the same item's balances at
     * those locations — an effective no-op that degenerates to "sum all
     * positive balances of the item". We implement the degenerate (literal
     * runtime) behaviour, not the apparent intent.
     */
    public static function applyReorderFlag(int $itemId): void
    {
        $item = DB::table('items')->where('items_id', $itemId)->first();
        if ($item === null || $item->srl_status !== 'Yes') {
            return;
        }

        $total = (float) StockLedgerGood::query()
            ->where('stlg_tritemid', $itemId)
            ->where('stlg_balqty', '>', 0)
            ->sum('stlg_balqty');

        if ($total <= (float) $item->srl && $item->srl_status !== 'OR') {
            StockLedgerGood::query()
                ->where('stlg_tritemid', $itemId)
                ->where('stlg_balqty', '>', 0)
                ->update(['orstatus' => 'R']);
        }
    }

    /**
     * Balance as-of date at an item x location (bincard reader).
     */
    public static function balanceAt(int $itemId, int $whid, int $binid, int $subbinid, string $asOf): array
    {
        $row = StockLedgerGood::query()
            ->where('stlg_tritemid', $itemId)
            ->where('stlg_whid', $whid)
            ->where('stlg_binid', $binid)
            ->where('stlg_subbinid', $subbinid)
            ->where('stlg_trdate', '<=', $asOf)
            ->orderByDesc('stlg_id')
            ->first();

        return $row
            ? ['ups' => (int) $row->stlg_balups, 'qty' => (float) $row->stlg_balqty]
            : ['ups' => 0, 'qty' => 0.0];
    }
}
