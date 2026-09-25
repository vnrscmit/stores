<?php

namespace App\Http\Controllers\Arrival;

use App\Http\Controllers\Controller;
use App\Models\Arrival;
use App\Models\ArrivalItem;
use App\Models\Classification;
use App\Models\Item;
use App\Models\QrCode;
use App\Support\Audit;
use App\Support\FiscalYear;
use App\Support\QrSerial;
use chillerlan\QRCode\Output\QRMarkupSVG;
use chillerlan\QRCode\QRCode as QrRenderer;
use chillerlan\QRCode\QROptions;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * The QR code generator — the port of Transaction/generate_qr_codes.php
 * (form + A4 print sheet), save_qr_codes.php (preview save + the qty_good
 * weight write-back) and save_qr_temp.php (draft save + purge), launched
 * from the vendor-arrival workspace (legacy menu link was dead:
 * utility/generate_qrcodes.php does not exist; see docs/PHASE10.md).
 *
 * One batch mints ONE code per good UPS of an arrival line, codes built
 * by QrSerial (plantcode + year4 + type2 + serial5, e.g. D25261100001,
 * serial continuing globally per year+type):
 *
 *  - DRAFT mode (no arrival yet — legacy form mode): purges the user's
 *    previous draft rows for the same item first (save_qr_temp.php
 *    semantics), then stores the batch as linked_status='draft' with
 *    arrival_id = arrsub_id = 0;
 *  - LINKED mode (arrival + line given — legacy preview mode): stores
 *    the batch as linked_status='linked' and — verbatim quirk —
 *    OVERWRITES arrival_items.qty_good with the Σ of the entered
 *    weights (only when Σ > 0, as legacy);
 *  - print: the A4 slip sheet (2×6 grid, 12 per page, Name/Item/Wt. per
 *    slip) with QRs rendered LOCALLY as inline SVG (deliberate
 *    deviation: legacy pulled images from api.qrserver.com).
 */
class QrCodeController extends Controller
{
    use AuthorizesRequests;

    /**
     * The generator form (legacy generate_qr_codes.php page). All three
     * modes are served here; the bound line carries both the arrival
     * present) and the line.
     */
    public function form(Request $request, ?Arrival $arrival = null, ?ArrivalItem $line = null): View
    {
        $this->authorize('post-transactions');

        $classificationId = (int) $request->query('classification_id');
        $itemId = (int) $request->query('item_id');
        $upsGood = (int) $request->query('ups_good');

        // Legacy required classification_id + item_id + ups_good > 0 in
        // every mode ("Error: Missing required parameters").
        abort_unless($classificationId > 0 && $itemId > 0 && $upsGood > 0, 422,
            'Missing required parameters: classification_id, item_id and ups_good are required.');

        $classification = Classification::query()->findOrFail($classificationId);
        $item = Item::query()->findOrFail($itemId);

        $yearcode = FiscalYear::yearcode();
        $typeCode = QrSerial::typeCodeFor($classification->classification_type ?? null);

        return view('arrivals.qr.form', [
            'arrival' => $arrival,
            'line' => $line,
            'classification' => $classification,
            'item' => $item,
            'upsGood' => $upsGood,
            'yearcode' => $yearcode,
            'typeCode' => $typeCode,
            'prefix' => QrSerial::prefix($yearcode, $typeCode),
            // Display-only peek — the serial range is consumed at save time.
            'serialStart' => QrSerial::peekSerial($yearcode, $typeCode),
        ]);
    }

    /**
     * Save a generated batch (legacy save_qr_codes.php in preview mode /
     * save_qr_temp.php in form mode — the JS switched endpoints; the
     * port switches mode by the presence of the arrival + line).
     */
    public function save(Request $request, ?Arrival $arrival = null, ?ArrivalItem $line = null): JsonResponse
    {
        $this->authorize('post-transactions');

        $validated = $request->validate([
            'classification_id' => ['required', 'integer'],
            'item_id' => ['required', 'integer'],
            'ups_good' => ['required', 'integer', 'min:1'],
            'serial_start' => ['required', 'integer', 'min:1'],
            'codes' => ['required', 'array', 'min:1'],
            'codes.*.text' => ['required', 'string'],
            'codes.*.weight' => ['nullable', 'numeric', 'min:0'],
        ]);

        $classificationId = (int) $validated['classification_id'];
        $itemId = (int) $validated['item_id'];

        $yearcode = FiscalYear::yearcode();
        $classification = Classification::query()->findOrFail($classificationId);
        $typeCode = QrSerial::typeCodeFor($classification->classification_type ?? null);

        $linked = $arrival !== null && $line !== null;

        // The bound pair must be consistent (the legacy form trusted its
        // hidden fields blindly).
        if ($linked) {
            abort_unless((int) $line->arrival_id === (int) $arrival->arrival_id, 422,
                'The arrival line does not belong to this arrival.');
        }

        $result = DB::transaction(function () use ($validated, $request, $arrival, $line, $linked, $classificationId, $itemId, $yearcode, $typeCode) {
            $userLogin = (string) ($request->user()?->login ?? '');

            // Legacy draft purge (save_qr_temp.php): delete this user's
            // previous draft rows for the same item before inserting.
            if (! $linked) {
                QrCode::query()
                    ->where('classification_id', $classificationId)
                    ->where('item_id', $itemId)
                    ->where('linked_status', 'draft')
                    ->where('created_by', $userLogin)
                    ->where('arrival_id', 0)
                    ->delete();
            }

            // Reserve the serial range the client displayed (any drift
            // between page load and save aborts, mirroring the legacy
            // prefix-requery race but failing loudly instead).
            $serialStart = (int) $validated['serial_start'];
            QrSerial::consumeBatch($yearcode, $typeCode, $serialStart, count($validated['codes']));

            $count = 0;
            $totalWeight = 0.0;

            foreach ($validated['codes'] as $code) {
                $weight = (float) ($code['weight'] ?? 0);
                $totalWeight += $weight;

                QrCode::query()->create([
                    'arrival_id' => $linked ? (int) $arrival->arrival_id : 0,
                    'arrsub_id' => $linked ? (int) $line->arrsub_id : 0,
                    'classification_id' => $classificationId,
                    'item_id' => $itemId,
                    'qr_code_text' => (string) $code['text'],
                    'weight' => $weight,
                    'generated_date' => now(),
                    'linked_status' => $linked ? 'linked' : 'draft',
                    'created_by' => $userLogin,
                ]);

                $count++;
            }

            // Verbatim quirk (save_qr_codes.php): the arrival line's good
            // quantity becomes the Σ of the weighed codes.
            if ($linked && $totalWeight > 0) {
                $line->qty_good = $totalWeight;
                $line->save();
            }

            Audit::log('arrival.qr', $linked ? 'save.linked' : 'save.draft', $arrival ?? $line, null, [
                'count' => $count,
                'total_weight' => $totalWeight,
            ]);

            return ['count' => $count, 'total_weight' => $totalWeight];
        });

        return response()->json([
            'ok' => true,
            'count' => $result['count'],
            'total_weight' => $result['total_weight'],
        ]);
    }

    /**
     * The A4 print sheet (legacy print_mode=true POST): 2×6 slip grid,
     * 12 per page, Name/Item/Wt. per slip, QR rendered locally as SVG.
     */
    public function print(Request $request): View
    {
        $this->authorize('post-transactions');

        $validated = $request->validate([
            'classification' => ['required', 'string', 'max:100'],
            'item_name' => ['required', 'string', 'max:255'],
            'uom' => ['nullable', 'string', 'max:20'],
            'type_code' => ['required', 'integer'],
            'codes' => ['required', 'array', 'min:1'],
            'codes.*.text' => ['required', 'string'],
            'codes.*.weight' => ['nullable', 'numeric', 'min:0'],
        ]);

        $codes = collect($validated['codes'])
            ->map(fn ($c) => [
                'text' => (string) $c['text'],
                'weight' => (float) ($c['weight'] ?? 0),
                'serial' => substr((string) $c['text'], -5),
            ]);

        $options = new QROptions([
            'outputInterface' => QRMarkupSVG::class,
            'outputBase64' => false,
            'svgAddXmlHeader' => false,
            'scale' => 3,
        ]);
        $renderer = new QrRenderer($options);

        return view('arrivals.qr.print', [
            'codes' => $codes,
            'classification' => (string) $validated['classification'],
            'itemName' => (string) $validated['item_name'],
            'uom' => (string) ($validated['uom'] ?? ''),
            'svg' => fn (string $text): string => (string) $renderer->render($text),
        ]);
    }

    /** The saved codes of an arrival line (workspace panel data). */
    public function codes(ArrivalItem $line): JsonResponse
    {
        $this->authorize('post-transactions');

        return response()->json(['ok' => true, 'codes' => QrCode::query()
            ->where('arrsub_id', $line->arrsub_id)
            ->orderBy('id')
            ->get(['id', 'qr_code_text', 'weight', 'linked_status'])
            ->all(),
        ]);
    }
}
