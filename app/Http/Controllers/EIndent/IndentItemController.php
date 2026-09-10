<?php

namespace App\Http\Controllers\EIndent;

use App\Http\Controllers\Controller;
use App\Models\EIndent;
use App\Models\EIndentItem;
use App\Models\Item;
use App\Support\Audit;
use App\Support\EIndentStatus;
use App\Support\FiscalYear;
use App\Support\IndentNumbering;
use Illuminate\Http\JsonResponse;

/**
 * Draft item-row workspace (AJAX), replicating the legacy
 * getuser_indentupdate.php / getuser_issue_eindent_etd.php /
 * getuser_issue_eindentdelete.php flow: posting the first row creates the
 * draft indent; each post adds or updates a row and re-renders the table.
 */
class IndentItemController extends Controller
{
    /** GET /eindents/classifications/{classification}/items — legacy getuser_indentuom.php. */
    public function byClassification(int $classification): JsonResponse
    {
        $items = Item::query()
            ->where('classification_id', $classification)
            ->where('actstatus', 'Active')
            ->orderBy('stores_item')
            ->get(['items_id', 'stores_item', 'uom']);

        return response()->json($items);
    }

    /** POST /eindents/items — add a row; creates the draft header on the first row (legacy getuser_indentupdate.php). */
    public function store(): JsonResponse
    {
        $tid = (int) request('tid', 0);

        if ($tid > 0) {
            $indent = EIndent::query()->findOrFail($tid);
            abort_unless($indent->tflg == 0, 422, 'Indent is already submitted and can no longer be edited.');
            abort_unless((int) $indent->id === auth()->id(), 403, 'You can only edit your own indents.');
        } else {
            // First row post of a fresh workspace creates the draft header
            // (legacy inserted tbl_ieindent with code1=next, tdate=today).
            abort_unless(EIndentController::canRaiseMore((int) auth()->id()), 422,
                'You already have 3 open indents (pending/approved). Wait for approval or issue.');

            $yearcode = FiscalYear::yearcode();

            $indent = EIndent::query()->create([
                'code1' => IndentNumbering::nextDraftCode($yearcode),
                'tdate' => now()->toDateString(),
                'id' => auth()->id(),
                'flg' => 0,
                'yearcode' => $yearcode,
                'tflg' => 0,
                'status' => EIndentStatus::DRAFT,
            ]);
        }

        $data = $this->validated();
        $indent->items()->create($data);

        return response()->json(['ok' => true, 'rows' => $this->renderRows($indent)]);
    }

    /** PUT|PATCH /eindent-items/{item} — update one draft row. */
    public function update(EIndentItem $item): JsonResponse
    {
        $indent = $item->eIndent;
        abort_if($indent === null, 404);
        abort_unless($indent->tflg == 0, 422, 'Indent is already submitted and can no longer be edited.');
        abort_unless((int) $indent->id === auth()->id(), 403, 'You can only edit your own indents.');

        $data = $this->validated();
        $before = $item->only(['classification_id', 'items_id', 'uom', 'ups', 'qty']);

        $item->update($data);

        Audit::log('eindent.item', 'update', $item, $before, $item->only(['classification_id', 'items_id', 'uom', 'ups', 'qty']));

        return response()->json(['ok' => true, 'rows' => $this->renderRows($indent)]);
    }

    /** DELETE /eindent-items/{item} — remove one draft row. */
    public function destroy(EIndentItem $item): JsonResponse
    {
        $indent = $item->eIndent;
        abort_if($indent === null, 404);
        abort_unless($indent->tflg == 0, 422, 'Indent is already submitted and can no longer be edited.');
        abort_unless((int) $indent->id === auth()->id(), 403, 'You can only edit your own indents.');

        $before = $item->only(['classification_id', 'items_id', 'uom', 'ups', 'qty']);
        $item->delete();

        Audit::log('eindent.item', 'delete', $item, $before);

        return response()->json(['ok' => true, 'rows' => $this->renderRows($indent)]);
    }

    /** Shared row rules (parity with the legacy JS guards, server-enforced). */
    private function validated(): array
    {
        $data = request()->validate([
            'classification_id' => ['required', 'integer', 'exists:classifications,classification_id'],
            'items_id' => ['required', 'integer', 'exists:items,items_id'],
            'uom' => ['required', 'string', 'max:50'],
            'qty' => ['required', 'numeric', 'min:0.001', 'max:9999999'],
        ]);

        $item = Item::query()->find($data['items_id']);

        abort_if($item === null || (int) $item->classification_id !== (int) $data['classification_id'],
            422, 'Selected item does not belong to the selected classification.');
        abort_if($item->actstatus !== 'Active', 422, 'Item is not active.');

        // Legacy enforces the item's own UoM (getuser_indentuom fills a
        // readonly box; the update handler stored whatever it was given).
        $data['uom'] = (string) $item->uom;
        $data['ups'] = 0;

        return $data;
    }

    /** Re-rendered draft table (legacy returns a full HTML fragment). */
    private function renderRows(EIndent $indent): array
    {
        return self::rowsPayload($indent);
    }

    /** Shared row payload for the AJAX table and the workspace view. */
    public static function rowsPayload(EIndent $indent): array
    {
        $rows = $indent->items()->with(['item', 'classification'])->get()->map(function (EIndentItem $row, int $i) {
            return [
                'eid' => $row->eid,
                'srno' => $i + 1,
                'classification_id' => (int) $row->classification_id,
                'items_id' => (int) $row->items_id,
                'classification' => $row->classification->classification ?? '',
                'item' => $row->item->stores_item ?? '',
                'uom' => $row->uom,
                'qty' => (float) $row->qty,
            ];
        });

        return [
            'tid' => $indent->tid,
            'transaction_id' => IndentNumbering::transactionId($indent),
            'rows' => $rows,
        ];
    }
}
