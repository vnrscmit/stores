<?php

namespace App\Http\Controllers\Masters;

use App\Http\Controllers\Controller;
use App\Http\Requests\Masters\WarehouseRequest;
use App\Models\Warehouse;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Warehouse master (legacy add/edit_warehouse.php + selectbin.php listing).
 * Parity: perticulars unique; bin delete cascades sub-bins; hard deletes.
 */
class WarehouseController extends Controller
{
    public function index(): View
    {
        $q = trim((string) request('q'));

        $warehouses = Warehouse::query()
            ->when($q !== '', fn ($query) => $query->where('perticulars', 'like', "%{$q}%"))
            ->withCount('bins')
            ->orderBy('perticulars')
            ->paginate(15)
            ->withQueryString();

        return view('masters.warehouses.index', [
            'warehouses' => $warehouses,
            'q' => $q,
        ]);
    }

    public function create(): View
    {
        return view('masters.warehouses.create');
    }

    public function store(WarehouseRequest $request): RedirectResponse
    {
        $warehouse = Warehouse::create(['perticulars' => trim($request->input('perticulars'))]);

        Audit::changed('masters.warehouse', 'create', $warehouse);

        return redirect()->route('masters.warehouses.index')->with('status', 'Warehouse created.');
    }

    public function edit(Warehouse $warehouse): View
    {
        return view('masters.warehouses.edit', ['warehouse' => $warehouse]);
    }

    public function update(WarehouseRequest $request, Warehouse $warehouse): RedirectResponse
    {
        $warehouse->update(['perticulars' => trim($request->input('perticulars'))]);

        Audit::changed('masters.warehouse', 'update', $warehouse);

        return redirect()->route('masters.warehouses.index')->with('status', 'Warehouse updated.');
    }

    public function destroy(Warehouse $warehouse): RedirectResponse
    {
        $before = ['whid' => $warehouse->whid, 'perticulars' => $warehouse->perticulars];

        // Legacy include/delete.php hard-deletes the warehouse.
        $warehouse->delete();

        Audit::log('masters.warehouse', 'delete', $warehouse, $before);

        return redirect()->route('masters.warehouses.index')->with('status', 'Warehouse deleted.');
    }
}
