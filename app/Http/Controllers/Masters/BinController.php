<?php

namespace App\Http\Controllers\Masters;

use App\Http\Controllers\Controller;
use App\Http\Requests\Masters\BinRequest;
use App\Models\Bin;
use App\Models\Warehouse;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Bin master (legacy add/edit_bin.php + bin_home.php listing).
 * Parity: binname unique per warehouse; creating a bin auto-creates
 * sub-bins 1..20 (legacy add_bin.php); deleting a bin cascades its
 * sub-bins — but transactionally, unlike the legacy two-step delete.
 */
class BinController extends Controller
{
    public function index(): View
    {
        $q = trim((string) request('q'));
        $whid = request('whid');

        $bins = Bin::query()
            ->with('warehouse')
            ->withCount('subBins')
            ->when($whid, fn ($query) => $query->where('whid', (int) $whid))
            ->when($q !== '', fn ($query) => $query->where('binname', 'like', "%{$q}%"))
            ->orderBy('whid')
            ->orderBy('binname')
            ->paginate(15)
            ->withQueryString();

        return view('masters.bins.index', [
            'bins' => $bins,
            'warehouses' => Warehouse::orderBy('perticulars')->get(),
            'whid' => $whid,
            'q' => $q,
        ]);
    }

    public function create(): View
    {
        return view('masters.bins.create', [
            'warehouses' => Warehouse::orderBy('perticulars')->get(),
        ]);
    }

    public function store(BinRequest $request): RedirectResponse
    {
        $bin = DB::transaction(function () use ($request) {
            $bin = Bin::create([
                'binname' => trim($request->input('binname')),
                'whid' => (int) $request->input('whid'),
            ]);

            // Legacy add_bin.php seeds sub-bins 1..20 for every new bin.
            $rows = [];
            for ($i = 1; $i <= 20; $i++) {
                $rows[] = ['sname' => $i, 'binid' => $bin->binid, 'whid' => $bin->whid, 'status' => 'Empty'];
            }
            DB::table('sub_bins')->insert($rows);

            return $bin;
        });

        Audit::changed('masters.bin', 'create', $bin);

        return redirect()
            ->route('masters.bins.index', ['whid' => $bin->whid])
            ->with('status', 'Bin created with sub-bins 1-20.');
    }

    public function edit(Bin $bin): View
    {
        return view('masters.bins.edit', [
            'bin' => $bin,
            'warehouses' => Warehouse::orderBy('perticulars')->get(),
        ]);
    }

    public function update(BinRequest $request, Bin $bin): RedirectResponse
    {
        $bin->update([
            'binname' => trim($request->input('binname')),
            'whid' => (int) $request->input('whid'),
        ]);

        Audit::changed('masters.bin', 'update', $bin);

        return redirect()->route('masters.bins.index', ['whid' => $bin->whid])->with('status', 'Bin updated.');
    }

    public function destroy(Bin $bin): RedirectResponse
    {
        $before = ['binid' => $bin->binid, 'binname' => $bin->binname, 'whid' => $bin->whid];
        $whid = $bin->whid;

        // Legacy cascades sub-bins in a second non-transactional query; the
        // port does both inside one transaction so a failure cannot leave
        // orphaned sub-bins behind.
        DB::transaction(function () use ($bin) {
            $bin->subBins()->delete();
            $bin->delete();
        });

        Audit::log('masters.bin', 'delete', $bin, $before);

        return redirect()->route('masters.bins.index', ['whid' => $whid])->with('status', 'Bin and its sub-bins deleted.');
    }
}
