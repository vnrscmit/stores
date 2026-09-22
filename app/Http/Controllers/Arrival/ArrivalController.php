<?php

namespace App\Http\Controllers\Arrival;

use App\Http\Controllers\Controller;
use App\Models\Arrival;
use App\Models\ArrivalItem;
use App\Models\ArrivalSloc;
use App\Models\Classification;
use App\Models\Item;
use App\Models\Party;
use App\Models\PartyLedger;
use App\Support\ArrivalNumbering;
use App\Support\ArrivalStatus;
use App\Support\ArrivalTypes;
use App\Support\Audit;
use App\Support\FiscalYear;
use App\Support\StockLedgerService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * The inbound arrivals family — vendor GRN (Phase 9 slice 2), stock transfer
 * in (slice 3) and internal return (slice 4) share this controller through
 * typed routes (arrivals/{vendor|stocktr|internal}/...). Reuses the Phase-6/7
 * posting skeleton:
 *
 *  1. The header (arrivals row, arrtrflag=0) is created with the first line
 *     post — legacy did the same via the getuser_*updateform AJAX (the
 *     trid=0 branch inserts tblarrival + tblarrival_sub + tblarr_sloc rows
 *     in one call). arrival_code = MAX(arrival_code)+1 per yearcode x type
 *     identifies the open workspace (legacy parity).
 *  2. Per line: DC quantities (vendor), good and damage totals plus the
 *     SLOC rows receiving them (arrival_slocs, good/damage columns on the
 *     same row — the legacy table shape). Sums must match the line totals
 *     and locations are verified server-side (legacy trusted the client).
 *  3. Final post (legacy add_arrival_vendor_preview.php frm_action=submit):
 *     per item x sloc row, the good branch writes a good-ledger row and the
 *     damage branch a damage-ledger twin (trtype 'Arrival', trsubtype per
 *     type, trid = arrival_id, partyid = the vendor) — sub-bin flips come
 *     from StockLedgerService. LEGACY QUIRK preserved verbatim: a sloc row
 *     carrying BOTH good and damage quantities posts only the damage side
 *     (the legacy single if/else); a warning is recorded in the audit row.
 *     Vendor GRN additionally writes the party-ledger row per item with the
 *     verbatim excess/shortage math (ex floored at 0, sh floored at 0 from
 *     above — see portArrivalItem), then the reorder pass, then the
 *     committed serials arr_code/ncode, arrtrflag=1. The post is wrapped in
 *     one DB transaction — legacy was not (a mid-loop failure left
 *     half-posted documents).
 */
class ArrivalController extends Controller
{
    use AuthorizesRequests;

    /** The arrivals queue: open workspaces + posted transactions per type. */
    public function index(string $type): View
    {
        $this->authorize('post-transactions');
        abort_unless(ArrivalTypes::META[$type] ?? null, 404);

        $arrivals = Arrival::query()
            ->where('arrival_type', ArrivalTypes::META[$type]['arrival_type'])
            ->where('yearcode', FiscalYear::yearcode())
            ->when(request('stage') === ArrivalStatus::OPEN, fn ($q) => $q->where('arrtrflag', 0))
            ->when(request('stage') === ArrivalStatus::POSTED, fn ($q) => $q->where('arrtrflag', 1))
            ->orderByDesc('arrival_id')
            ->paginate(20)
            ->withQueryString();

        return view('arrivals.queue', [
            'type' => $type,
            'meta' => ArrivalTypes::META[$type],
            'arrivals' => $arrivals,
        ]);
    }

    /** Entry screen: header fields for the type + the first item line. */
    public function create(string $type): View
    {
        $this->authorize('post-transactions');
        abort_unless(ArrivalTypes::META[$type] ?? null, 404);

        return view('arrivals.create', [
            'type' => $type,
            'meta' => ArrivalTypes::META[$type],
            'classifications' => Classification::query()->orderBy('classification')->get(['classification_id', 'classification']),
            'parties' => ArrivalTypes::hasPartyLedger($type) || $type === ArrivalTypes::INTERNAL_RETURN
                ? Party::query()->orderBy('business_name')->get(['p_id', 'business_name'])
                : collect(),
        ]);
    }

    /**
     * Save a line (AJAX, legacy getuser_vupdateform.php + siblings). With no
     * arrival_id the header is created first (legacy trid=0 branch) with the
     * full vendor/transport block — the preview screen's later update is the
     * updateHeader endpoint.
     */
    public function storeLine(Request $request, string $type): JsonResponse
    {
        $this->authorize('post-transactions');
        abort_unless(ArrivalTypes::META[$type] ?? null, 404);

        $data = $this->validateLine($request, $type);

        $arrival = isset($data['arrival_id'])
            ? $this->findOpenArrival((int) $data['arrival_id'], $type)
            : null;

        $result = DB::transaction(function () use ($data, $type, &$arrival) {
            $arrival ??= $this->createHeader($type, $data);

            $line = $this->saveLine($arrival, $data);

            Audit::log(ArrivalTypes::auditModule($type), 'line.create', $line, null, [
                'item' => $data['items_id'],
                'good' => $data['qty_good'],
                'damage' => $data['qty_damage'],
                'slocs' => count($data['slocs']),
            ]);

            return $this->lineState($line, $arrival);
        });

        return response()->json([
            'ok' => true,
            'arrival_id' => $arrival->arrival_id,
            'redirect' => route("arrivals.{$type}.workspace", $arrival),
            'line' => $result,
        ]);
    }

    /** Replace one line and its sloc rows (legacy edtupdate semantics). */
    public function updateLine(Request $request, ArrivalItem $line): JsonResponse
    {
        $this->authorize('post-transactions');

        $arrival = $this->findOpenArrival((int) $line->arrival_id);
        $type = $this->typeOf($arrival);

        $data = $this->validateLine($request, $type);

        $result = DB::transaction(function () use ($arrival, $line, $data, $type) {
            ArrivalSloc::query()
                ->where('arr_tr_id', $arrival->arrival_id)
                ->where('arr_id', $line->arrsub_id)
                ->delete();

            $updated = $this->saveLine($arrival, $data, $line);

            Audit::log(ArrivalTypes::auditModule($type), 'line.update', $updated, null, [
                'item' => $data['items_id'], 'good' => $data['qty_good'], 'damage' => $data['qty_damage'],
            ]);

            return $this->lineState($updated, $arrival);
        });

        return response()->json(['ok' => true, 'line' => $result]);
    }

    /** Remove one line + its sloc rows (legacy getuser_*delete family). */
    public function deleteLine(ArrivalItem $line): JsonResponse
    {
        $this->authorize('post-transactions');

        $arrival = $this->findOpenArrival((int) $line->arrival_id);
        $type = $this->typeOf($arrival);

        $deleted = DB::transaction(function () use ($arrival, $line, $type) {
            ArrivalSloc::query()
                ->where('arr_tr_id', $arrival->arrival_id)
                ->where('arr_id', $line->arrsub_id)
                ->delete();

            Audit::log(ArrivalTypes::auditModule($type), 'line.delete', $arrival, ['item' => $line->item_id]);

            return $line->delete();
        });

        abort_unless($deleted, 404, 'Nothing to delete.');

        return response()->json(['ok' => true]);
    }

    /** The workspace: existing lines with their sloc rows, add-line form. */
    public function workspace(Arrival $arrival): View
    {
        $this->authorize('post-transactions');
        $type = $this->typeOf($arrival);

        $arrival->load(['items.slocs']);

        $lines = $arrival->items->map(fn (ArrivalItem $line) => $this->lineState($line, $arrival));

        return view('arrivals.workspace', [
            'type' => $type,
            'meta' => ArrivalTypes::META[$type],
            'arrival' => $arrival,
            'lines' => $lines,
            'partyName' => $arrival->party_id
                ? (string) (Party::query()->find($arrival->party_id)?->business_name ?? '—')
                : '—',
            'classifications' => Classification::query()->orderBy('classification')->get(['classification_id', 'classification']),
        ]);
    }

    /** Edit the header fields while open (legacy preview-screen update). */
    public function updateHeader(Request $request, Arrival $arrival): RedirectResponse
    {
        $this->authorize('post-transactions');
        $type = $this->typeOf($arrival);
        abort_if($arrival->isPosted(), 422, 'Posted arrivals are immutable.');

        $data = $request->validate($this->headerRules($type));

        $arrival->fill($this->headerFields($type, $data) + ['remarks' => $data['remarks'] ?? $arrival->remarks]);
        $arrival->save();

        Audit::log(ArrivalTypes::auditModule($type), 'header.update', $arrival);

        return back()->with('success', 'Header updated.');
    }

    /**
     * Final post (legacy add_arrival_vendor_preview.php): writes every sloc
     * row through StockLedgerService (direction in), the vendor party-ledger
     * row per item, the reorder pass and the committed serials.
     * Transactional and idempotent — legacy was neither.
     */
    public function post(Arrival $arrival): RedirectResponse
    {
        $this->authorize('post-transactions');
        $type = $this->typeOf($arrival);

        if ($arrival->isPosted()) {
            return redirect()->route("arrivals.{$type}.show", $arrival)
                ->with('error', 'This arrival has already been posted.');
        }

        $yearcode = FiscalYear::yearcode();

        $posted = DB::transaction(function () use ($arrival, $type, $yearcode) {
            $lines = $arrival->items()->get();
            abort_unless($lines->isNotEmpty(), 422, 'This arrival has no item lines.');

            $trtype = 'Arrival';
            $trsubtype = ArrivalTypes::trsubtype($type);
            $partyid = (string) ($arrival->party_id ?: 0);
            $mixedRows = 0;

            foreach ($lines as $line) {
                $slocs = ArrivalSloc::query()
                    ->where('arr_tr_id', $arrival->arrival_id)
                    ->where('arr_id', $line->arrsub_id)
                    ->get();
                abort_unless($slocs->isNotEmpty(), 422,
                    'Line for item '.$line->item_id.' has no stock-location rows yet.');

                // The saved sloc rows must still cover the line totals.
                $sumGood = (float) $slocs->sum('qty_good');
                $sumDamage = (float) $slocs->sum('qty_damage');
                abort_unless(abs($sumGood - (float) $line->qty_good) < 0.001, 422,
                    'Good distribution ('.$sumGood.') no longer matches the line quantity ('.$line->qty_good.').');
                abort_unless(abs($sumDamage - (float) $line->qty_damage) < 0.001, 422,
                    'Damage distribution ('.$sumDamage.') no longer matches the line quantity ('.$line->qty_damage.').');

                foreach ($slocs as $sloc) {
                    // LEGACY QUIRK (verbatim): qty_damage==0 && ups_damage==0
                    // -> good branch; otherwise the damage branch only, so a
                    // row with both quantities posts only its damage side.
                    if ((float) $sloc->qty_damage == 0 && (int) $sloc->ups_damage == 0) {
                        StockLedgerService::post([
                            'direction' => 'in',
                            'yearcode' => $yearcode,
                            'trtype' => $trtype,
                            'trsubtype' => $trsubtype,
                            'trid' => $arrival->arrival_id,
                            'partyid' => $partyid,
                            'trdate' => $arrival->arrival_date,
                            'classid' => $line->classification_id,
                            'item_id' => $line->item_id,
                            'whid' => (int) $sloc->whid,
                            'binid' => (int) $sloc->binid,
                            'subbinid' => (int) $sloc->subbin,
                            'ups' => (int) $sloc->ups_good,
                            'qty' => (float) $sloc->qty_good,
                        ]);
                    } else {
                        if ((float) $sloc->qty_good > 0 || (int) $sloc->ups_good > 0) {
                            $mixedRows++;
                        }

                        StockLedgerService::post([
                            'direction' => 'in',
                            'damage' => true,
                            'yearcode' => $yearcode,
                            'trtype' => $trtype,
                            'trsubtype' => $trsubtype,
                            'trid' => $arrival->arrival_id,
                            'partyid' => $partyid,
                            'trdate' => $arrival->arrival_date,
                            'classid' => $line->classification_id,
                            'item_id' => $line->item_id,
                            'whid' => (int) $sloc->whid,
                            'binid' => (int) $sloc->binid,
                            'subbinid' => (int) $sloc->subbin,
                            'ups' => (int) $sloc->ups_damage,
                            'qty' => (float) $sloc->qty_damage,
                        ]);
                    }
                }

                // Vendor GRN: the party-ledger row per item (verbatim legacy
                // excess/shortage math — see portArrivalItem notes).
                if (ArrivalTypes::hasPartyLedger($type)) {
                    $this->portArrivalItem($arrival, $line, $yearcode);
                }

                StockLedgerService::applyReorderFlag((int) $line->item_id);
            }

            $arrival->arr_code = ArrivalNumbering::primeCommitted($yearcode, $type);
            $arrival->ncode = ArrivalNumbering::primeNote($yearcode, $type);
            $arrival->arrtrflag = 1;
            $arrival->status = ArrivalStatus::POSTED;
            $arrival->save();

            Audit::log(ArrivalTypes::auditModule($type), 'post', $arrival, null, [
                'arr_code' => $arrival->arr_code,
                'ncode' => $arrival->ncode,
                'lines' => $lines->count(),
                'mixed_sloc_rows_dropped_good_side' => $mixedRows,
            ]);

            return $arrival;
        });

        return redirect()
            ->route("arrivals.{$type}.show", $posted)
            ->with('success', 'Arrival posted: '.ArrivalNumbering::transactionId($posted, $type).'. Stock updated.');
    }

    /** Detail screen (legacy add_arrival_vendor_preview.php render). */
    public function show(Arrival $arrival): View
    {
        $this->authorize('post-transactions');
        $type = $this->typeOf($arrival);

        $arrival->load(['items.slocs']);
        $party = $arrival->party_id ? Party::query()->find($arrival->party_id) : null;

        return view('arrivals.show', [
            'type' => $type,
            'meta' => ArrivalTypes::META[$type],
            'arrival' => $arrival,
            'party' => $party,
            'partyName' => $party?->business_name ?? '—',
        ]);
    }

    // ------------------------------------------------------------------

    /** Resolve a route-bound arrival's type key from its legacy literal. */
    private function typeOf(Arrival $arrival): string
    {
        foreach (ArrivalTypes::META as $key => $meta) {
            if (strcasecmp($meta['arrival_type'], (string) $arrival->arrival_type) === 0) {
                return $key;
            }
        }

        abort(404, 'Unknown arrival type.');
    }

    /** Validation for a line post, including per-type header fields. */
    private function validateLine(Request $request, string $type): array
    {
        $data = $request->validate($this->headerRules($type) + [
            'arrival_id' => ['nullable', 'integer'],
            'classification_id' => ['required', 'integer'],
            'items_id' => ['required', 'integer'],
            'ups_per_dc' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'qty_per_dc' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'qty_good' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'ups_good' => ['required', 'integer', 'min:0', 'max:999999'],
            'qty_damage' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'ups_damage' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'slocs' => ['required', 'array', 'min:1'],
            'slocs.*.whid' => ['required', 'integer'],
            'slocs.*.binid' => ['required', 'integer'],
            'slocs.*.subbin' => ['required', 'integer'],
            'slocs.*.qty_good' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'slocs.*.ups_good' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'slocs.*.qty_damage' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'slocs.*.ups_damage' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'slocs.*.stlg_id' => ['nullable', 'integer'],
        ]);

        // The item must exist, be active and belong to the classification
        // (legacy trusted the client chain; the port verifies server-side).
        $item = Item::query()->find((int) $data['items_id']);
        abort_if($item === null, 422, 'Item not found.');
        abort_unless((int) $item->classification_id === (int) $data['classification_id'], 422,
            'The selected item does not belong to the selected classification.');
        abort_unless($item->actstatus === 'Active', 422, 'The item is inactive.');

        $data['uom'] = (string) $item->uom;
        $data['qty_per_dc'] = (float) ($data['qty_per_dc'] ?? 0);
        $data['ups_per_dc'] = (int) ($data['ups_per_dc'] ?? 0);
        $data['qty_damage'] = (float) ($data['qty_damage'] ?? 0);
        $data['ups_damage'] = (int) ($data['ups_damage'] ?? 0);

        // At least one receiving side must be positive.
        abort_unless($data['qty_good'] > 0 || $data['qty_damage'] > 0, 422,
            'A line must carry a good or a damage quantity.');

        // Every sloc row must reference real locations (legacy validated in
        // JS only; the port verifies the wh -> bin -> sub-bin chain).
        $sumGood = 0.0;
        $sumGoodUps = 0;
        $sumDamage = 0.0;
        $sumDamageUps = 0;

        foreach ($data['slocs'] as $i => $sloc) {
            $whid = (int) $sloc['whid'];
            $binid = (int) $sloc['binid'];
            $subbinid = (int) $sloc['subbin'];

            abort_unless(DB::table('warehouses')->where('whid', $whid)->exists(), 422, "SLOC row {$i}: warehouse not found.");
            $bin = DB::table('bins')->where('binid', $binid)->where('whid', $whid)->first();
            abort_if($bin === null, 422, "SLOC row {$i}: the bin does not belong to the warehouse.");
            $subbin = DB::table('sub_bins')->where('sid', $subbinid)->where('binid', $binid)->where('whid', $whid)->first();
            abort_if($subbin === null, 422, "SLOC row {$i}: the sub-bin does not belong to the bin.");

            $goodQty = (float) ($sloc['qty_good'] ?? 0);
            $goodUps = (int) ($sloc['ups_good'] ?? 0);
            $damageQty = (float) ($sloc['qty_damage'] ?? 0);
            $damageUps = (int) ($sloc['ups_damage'] ?? 0);

            abort_unless($goodQty > 0 || $damageQty > 0, 422, "SLOC row {$i}: nothing to receive.");

            $sumGood += $goodQty;
            $sumGoodUps += $goodUps;
            $sumDamage += $damageQty;
            $sumDamageUps += $damageUps;
        }

        abort_unless(abs($sumGood - $data['qty_good']) < 0.001, 422,
            'Good distribution ('.$sumGood.') must equal the line good quantity ('.$data['qty_good'].').');
        abort_unless($sumGoodUps === $data['ups_good'], 422,
            'Good distribution UPS ('.$sumGoodUps.') must equal the line good UPS ('.$data['ups_good'].').');
        abort_unless(abs($sumDamage - $data['qty_damage']) < 0.001, 422,
            'Damage distribution ('.$sumDamage.') must equal the line damage quantity ('.$data['qty_damage'].').');
        abort_unless($sumDamageUps === $data['ups_damage'], 422,
            'Damage distribution UPS ('.$sumDamageUps.') must equal the line damage UPS ('.$data['ups_damage'].').');

        return $data;
    }

    /** Per-type header validation (legacy conditional transport fields). */
    private function headerRules(string $type): array
    {
        $modeFields = [
            'tmode' => ['required', 'in:Transport,Courier,By Hand'],
            'trans_name' => ['nullable', 'string', 'max:100'],
            'trans_lorryrepno' => ['nullable', 'string', 'max:50'],
            'trans_vehno' => ['nullable', 'string', 'max:50'],
            'trans_paymode' => ['nullable', 'string', 'max:50'],
            'courier_name' => ['nullable', 'string', 'max:100'],
            'docket_no' => ['nullable', 'string', 'max:50'],
            'pname_byhand' => ['nullable', 'string', 'max:250'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ];

        return match ($type) {
            ArrivalTypes::VENDOR => [
                'party_id' => ['required', 'integer', 'exists:parties,p_id'],
                'dcno' => ['required', 'string', 'max:50'],   // legacy txtdcno: D.C./Inv. No
                'porefno' => ['nullable', 'string', 'max:50'],
                'arrival_date' => ['required', 'date'],
            ] + $modeFields,
            ArrivalTypes::STOCK_TRANSFER => [
                'stnno' => ['required', 'string', 'max:50'],  // legacy STN no
                'arrival_date' => ['required', 'date'],
            ] + $modeFields,
            ArrivalTypes::INTERNAL_RETURN => [
                'party_id' => ['required', 'integer', 'exists:parties,p_id'],
                'stageret' => ['required', 'string', 'max:100'], // returned-from stage
                'retid' => ['required', 'string', 'max:100'],    // returned-by
                'type' => ['required', 'in:Good'],               // legacy stores 'Good'
                'arrival_date' => ['required', 'date'],
            ] + $modeFields,
            default => abort(404),
        };
    }

    /** Map validated header fields onto the arrivals columns (legacy names). */
    private function headerFields(string $type, array $data): array
    {
        $fields = [
            'tmode' => $data['tmode'] ?? null,
            'trans_name' => $data['trans_name'] ?? null,
            'trans_lorryrepno' => $data['trans_lorryrepno'] ?? null,
            'trans_vehno' => $data['trans_vehno'] ?? null,
            'trans_paymode' => $data['trans_paymode'] ?? null,
            'courier_name' => $data['courier_name'] ?? null,
            'docket_no' => $data['docket_no'] ?? null,
            'pname_byhand' => $data['pname_byhand'] ?? null,
        ];

        return match ($type) {
            ArrivalTypes::VENDOR => [
                'party_id' => (int) $data['party_id'],
                'dcno' => $data['dcno'],
                'porefno' => $data['porefno'] ?? null,
                'arrival_date' => $data['arrival_date'],
            ] + $fields,
            ArrivalTypes::STOCK_TRANSFER => [
                'stnno' => $data['stnno'],
                'arrival_date' => $data['arrival_date'],
            ] + $fields,
            ArrivalTypes::INTERNAL_RETURN => [
                'party_id' => (int) $data['party_id'],
                'stageret' => $data['stageret'],
                'retid' => $data['retid'],
                'type' => $data['type'],
                'arrival_date' => $data['arrival_date'],
            ] + $fields,
            default => [],
        };
    }

    /** Find this type's open workspace by id (legacy keyed lookups, typed). */
    private function findOpenArrival(int $arrivalId, ?string $type = null): Arrival
    {
        $arrival = Arrival::query()
            ->where('arrival_id', $arrivalId)
            ->where('yearcode', FiscalYear::yearcode())
            ->first();

        abort_if($arrival === null, 404, 'Arrival workspace not found.');
        abort_if($type !== null && strcasecmp(
            ArrivalTypes::META[$type]['arrival_type'],
            (string) $arrival->arrival_type
        ) !== 0, 404, 'Arrival workspace not found.');
        abort_if($arrival->isPosted(), 422, 'This arrival has already been posted and is immutable.');

        return $arrival;
    }

    /** Create the header on first line post (legacy trid=0 branch). */
    private function createHeader(string $type, array $header): Arrival
    {
        $yearcode = FiscalYear::yearcode();

        $arrival = Arrival::query()->create($this->headerFields($type, $header) + [
            'arrival_type' => ArrivalTypes::META[$type]['arrival_type'],
            'arrival_code' => ArrivalNumbering::nextWorkspaceCode($yearcode, $type),
            'yearcode' => $yearcode,
            'arrtrflag' => 0,
            'arr_role' => (string) auth()->id(),
            'status' => ArrivalStatus::OPEN,
            'remarks' => $header['remarks'] ?? null,
        ]);

        Audit::log(ArrivalTypes::auditModule($type), 'open', $arrival);

        return $arrival;
    }

    /**
     * Persist one line + its sloc rows (create or replace). $line is passed
     * for the replace path (legacy delete-and-reinsert of the slocs only).
     */
    private function saveLine(Arrival $arrival, array $data, ?ArrivalItem $line = null): ArrivalItem
    {
        $isNew = $line === null;
        $line ??= new ArrivalItem(['arrival_id' => $arrival->arrival_id]);

        // Excess/shortage, verbatim legacy math from the posting preview
        // (ex floored at 0; sh floored at 0 from above, i.e. stored <= 0).
        $exQty = max(0, $data['qty_good'] + $data['qty_damage'] - $data['qty_per_dc']);
        $exUps = max(0, $data['ups_good'] + $data['ups_damage'] - $data['ups_per_dc']);
        $shQty = $data['qty_per_dc'] - $data['qty_good'] + $data['qty_damage'];
        if ($shQty > 0) {
            $shQty = 0;
        }
        $shUps = $data['ups_per_dc'] - $data['ups_good'] + $data['ups_damage'];
        if ($shUps > 0) {
            $shUps = 0;
        }

        $goodRows = collect($data['slocs'])->filter(fn ($s) => (float) ($s['qty_good'] ?? 0) > 0)->count();
        $damageRows = collect($data['slocs'])->filter(fn ($s) => (float) ($s['qty_damage'] ?? 0) > 0)->count();

        $line->fill([
            'classification_id' => (int) $data['classification_id'],
            'item_id' => (int) $data['items_id'],
            'qty_per_dc' => $data['qty_per_dc'],
            'ups_per_dc' => $data['ups_per_dc'],
            'qty_good' => $data['qty_good'],
            'ups_good' => $data['ups_good'],
            'qty_damage' => $data['qty_damage'],
            'ups_damage' => $data['ups_damage'],
            'exsh_qty' => $exQty !== 0.0 ? $exQty : $shQty,
            'exsh_ups' => $exUps !== 0 ? $exUps : $shUps,
            'noofbin_good' => $goodRows,
            'noofbin_damage' => $damageRows,
            'uom' => $data['uom'],
        ]);
        $line->save();

        foreach ($data['slocs'] as $sloc) {
            ArrivalSloc::query()->create([
                'arr_type' => ArrivalTypes::trsubtype($this->typeOf($arrival)),
                'arr_tr_id' => $arrival->arrival_id,
                'arr_id' => $line->arrsub_id,
                'classification_id' => (int) $data['classification_id'],
                'item_id' => (int) $data['items_id'],
                'whid' => (int) $sloc['whid'],
                'binid' => (int) $sloc['binid'],
                'subbin' => (int) $sloc['subbin'],
                'qty_good' => (float) ($sloc['qty_good'] ?? 0),
                'ups_good' => (int) ($sloc['ups_good'] ?? 0),
                'qty_damage' => (float) ($sloc['qty_damage'] ?? 0),
                'ups_damage' => (int) ($sloc['ups_damage'] ?? 0),
                'rowid' => $sloc['stlg_id'] ?? null,
            ]);
        }

        if ($isNew) {
            $line->wasRecentlyCreated = true;
        }

        return $line;
    }

    /**
     * The vendor party-ledger row per item (legacy preview verbatim):
     * dc quantities from the line; good/damage from the line; excess = good
     * + damage - dc floored at 0; shortage = dc - good + damage floored at 0
     * from above (stored <= 0 — party-ledger parity with the partywise
     * report); balance = latest party-ledger balance for (party, class,
     * item) + the good side — the damage side is excluded from the balance
     * in legacy, preserved.
     */
    private function portArrivalItem(Arrival $arrival, ArrivalItem $line, string $yearcode): void
    {
        $dcQty = (float) $line->qty_per_dc;
        $dcUps = (int) $line->ups_per_dc;
        $goodQty = (float) $line->qty_good;
        $goodUps = (int) $line->ups_good;
        $damageQty = (float) $line->qty_damage;
        $damageUps = (int) $line->ups_damage;

        $exQty = max(0, $goodQty + $damageQty - $dcQty);
        $shQty = $dcQty - $goodQty + $damageQty;
        if ($shQty > 0) {
            $shQty = 0;
        }

        $latest = PartyLedger::query()
            ->where('pldg_trpartyid', (int) $arrival->party_id)
            ->where('pldg_trclassid', (int) $line->classification_id)
            ->where('pldg_tritemid', (int) $line->item_id)
            ->orderByDesc('pldg_id')
            ->first();

        $balanceUps = $latest !== null ? (int) $latest->pldg_trbalups + $goodUps : $goodUps;
        $balanceQty = $latest !== null ? (float) $latest->pldg_trbalqty + $goodQty : $goodQty;

        PartyLedger::query()->create([
            'yearcode' => $yearcode,
            'pldg_trtype' => 'Arrival',
            'pldg_trsubtype' => ArrivalTypes::trsubtype($this->typeOf($arrival)),
            'pldg_trid' => $arrival->arrival_id,
            'pldg_trpartyid' => (int) $arrival->party_id,
            'pldg_trdate' => $arrival->arrival_date,
            'pldg_trclassid' => (int) $line->classification_id,
            'pldg_tritemid' => (int) $line->item_id,
            'pldg_trdcups' => $dcUps,
            'pldg_trdcqty' => $dcQty,
            'pldg_trgoodups' => $goodUps,
            'pldg_trgoodqty' => $goodQty,
            'pldg_trdamageups' => $damageUps,
            'pldg_trdamageqty' => $damageQty,
            'pldg_trexqty' => $exQty,
            'pldg_trshqty' => $shQty,
            'pldg_trbalups' => $balanceUps,
            'pldg_trbalqty' => $balanceQty,
        ]);
    }

    /**
     * Per-line state for the workspace: the saved sloc rows (availability is
     * offered by the shared availability endpoint — receiving does not draw
     * down existing stock, so the rows are reference-only).
     */
    private function lineState(ArrivalItem $line, Arrival $arrival): array
    {
        $distributed = ArrivalSloc::query()
            ->where('arr_tr_id', $arrival->arrival_id)
            ->where('arr_id', $line->arrsub_id)
            ->get()
            ->map(fn (ArrivalSloc $s) => [
                'arrsloc_id' => $s->arrsloc_id,
                'whid' => (int) $s->whid,
                'binid' => (int) $s->binid,
                'subbinid' => (int) $s->subbin,
                'ups_good' => (int) $s->ups_good,
                'qty_good' => (float) $s->qty_good,
                'ups_damage' => (int) $s->ups_damage,
                'qty_damage' => (float) $s->qty_damage,
            ]);

        return [
            'line' => $line,
            'distributed' => $distributed,
            'distributed_good' => (float) $distributed->sum('qty_good'),
            'distributed_damage' => (float) $distributed->sum('qty_damage'),
        ];
    }
}
