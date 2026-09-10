<?php

namespace App\Http\Controllers\Masters;

use App\Http\Controllers\Controller;
use App\Http\Requests\Masters\ItemRequest;
use App\Models\Classification;
use App\Models\Item;
use App\Support\Audit;
use App\Support\Excel;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Item master (legacy add/edit_stores.php + stores_home.php).
 * Parity: stores_item unique; UoM list; srl Yes/No with reorder level;
 * actstatus Active/Suspend; hard delete (include/delete.php print=stores).
 */
class ItemController extends Controller
{
    public function index(): View
    {
        $q = trim((string) request('q'));
        $classificationId = request('classification_id');
        $status = request('actstatus');

        $items = Item::query()
            ->with('classification')
            ->when($q !== '', fn ($query) => $query->where('stores_item', 'like', "%{$q}%"))
            ->when($classificationId, fn ($query) => $query->where('classification_id', (int) $classificationId))
            ->when($status, fn ($query) => $query->where('actstatus', $status))
            ->orderBy('stores_item')
            ->paginate(15)
            ->withQueryString();

        return view('masters.items.index', [
            'items' => $items,
            'classifications' => Classification::orderBy('classification')->get(),
            'q' => $q,
            'classificationId' => $classificationId,
            'status' => $status,
        ]);
    }

    public function export(): BinaryFileResponse|StreamedResponse
    {
        $q = trim((string) request('q'));
        $classificationId = request('classification_id');
        $status = request('actstatus');

        $rows = Item::query()
            ->with('classification')
            ->when($q !== '', fn ($query) => $query->where('stores_item', 'like', "%{$q}%"))
            ->when($classificationId, fn ($query) => $query->where('classification_id', (int) $classificationId))
            ->when($status, fn ($query) => $query->where('actstatus', $status))
            ->orderBy('stores_item')
            ->get()
            ->map(fn (Item $i) => [
                'items_id' => $i->items_id,
                'classification' => $i->classification?->classification,
                'stores_item' => $i->stores_item,
                'uom' => $i->uom,
                'srl_status' => $i->srl_status,
                'srl' => $i->srl,
                'actstatus' => $i->actstatus,
            ]);

        return Excel::stream(
            'items-master-'.now()->format('Ymd').'.xlsx',
            ['ID', 'Classification', 'Item', 'UoM', 'Serial Tracking', 'Reorder Level', 'Status'],
            $rows
        );
    }

    public function create(): View
    {
        return view('masters.items.create', [
            'classifications' => Classification::orderBy('classification')->get(),
            'uoms' => ['Number', 'Kg', 'Meters', 'Litres', 'Mililitres'],
        ]);
    }

    public function store(ItemRequest $request): RedirectResponse
    {
        $item = Item::create([
            'classification_id' => (int) $request->input('classification_id'),
            'stores_item' => trim($request->input('stores_item')),
            'uom' => $request->input('uom'),
            'srl_status' => $request->input('srl_status'),
            'srl' => $request->input('srl_status') === 'Yes' ? $request->input('srl') : null,
            'actstatus' => $request->input('actstatus'),
        ]);

        Audit::changed('masters.item', 'create', $item);

        return redirect()->route('masters.items.index')->with('status', 'Item created.');
    }

    public function edit(Item $item): View
    {
        return view('masters.items.edit', [
            'item' => $item,
            'classifications' => Classification::orderBy('classification')->get(),
            'uoms' => ['Number', 'Kg', 'Meters', 'Litres', 'Mililitres'],
        ]);
    }

    public function update(ItemRequest $request, Item $item): RedirectResponse
    {
        $item->update([
            'classification_id' => (int) $request->input('classification_id'),
            'stores_item' => trim($request->input('stores_item')),
            'uom' => $request->input('uom'),
            'srl_status' => $request->input('srl_status'),
            'srl' => $request->input('srl_status') === 'Yes' ? $request->input('srl') : null,
            'actstatus' => $request->input('actstatus'),
        ]);

        Audit::changed('masters.item', 'update', $item);

        return redirect()->route('masters.items.index')->with('status', 'Item updated.');
    }

    public function destroy(Item $item): RedirectResponse
    {
        $before = [
            'items_id' => $item->items_id,
            'stores_item' => $item->stores_item,
            'actstatus' => $item->actstatus,
        ];

        $item->delete();

        Audit::log('masters.item', 'delete', $item, $before);

        return redirect()->route('masters.items.index')->with('status', 'Item deleted.');
    }
}
