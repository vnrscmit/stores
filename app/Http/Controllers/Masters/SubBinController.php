<?php

namespace App\Http\Controllers\Masters;

use App\Http\Controllers\Controller;
use App\Http\Requests\Masters\SubBinRequest;
use App\Models\Bin;
use App\Models\SubBin;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Sub-bin / SLOC master (legacy add/edit_subbin.php + subbin listings).
 * Parity: sname numeric 1..N; status defaults to Empty. Deliberate fixes
 * (documented): uniqueness is scoped per bin (legacy checked globally),
 * and update targets a specific sid (legacy UPDATE rewrote every sub-bin
 * of the bin because it filtered only on binid).
 */
class SubBinController extends Controller
{
    public function index(): View
    {
        $q = trim((string) request('q'));
        $binid = request('binid');

        $subBins = SubBin::query()
            ->with('bin.warehouse')
            ->when($binid, fn ($query) => $query->where('binid', (int) $binid))
            ->when($q !== '', fn ($query) => $query->where('sname', 'like', "%{$q}%"))
            ->orderBy('binid')
            ->orderBy('sname')
            ->paginate(20)
            ->withQueryString();

        return view('masters.subbins.index', [
            'subBins' => $subBins,
            'bins' => Bin::with('warehouse')->orderBy('binname')->get(),
            'binid' => $binid,
            'q' => $q,
        ]);
    }

    public function create(): View
    {
        return view('masters.subbins.create', [
            'bins' => Bin::with('warehouse')->orderBy('binname')->get(),
        ]);
    }

    public function store(SubBinRequest $request): RedirectResponse
    {
        $bin = Bin::findOrFail($request->input('binid'));

        $subBin = SubBin::create([
            'sname' => (int) $request->input('sname'),
            'binid' => $bin->binid,
            'whid' => $bin->whid, // derived from the bin, never input
            'status' => $request->input('status') ?: 'Empty',
        ]);

        Audit::changed('masters.subbin', 'create', $subBin);

        return redirect()->route('masters.subbins.index', ['binid' => $subBin->binid])->with('status', 'Sub-bin created.');
    }

    public function edit(SubBin $subbin): View
    {
        return view('masters.subbins.edit', [
            'subBin' => $subbin,
            'bins' => Bin::with('warehouse')->orderBy('binname')->get(),
        ]);
    }

    public function update(SubBinRequest $request, SubBin $subbin): RedirectResponse
    {
        $bin = Bin::findOrFail($request->input('binid'));

        $subbin->update([
            'sname' => (int) $request->input('sname'),
            'binid' => $bin->binid,
            'whid' => $bin->whid, // derived from the bin, never input
            'status' => $request->input('status') ?: $subbin->status,
        ]);

        Audit::changed('masters.subbin', 'update', $subbin);

        return redirect()->route('masters.subbins.index', ['binid' => $subbin->binid])->with('status', 'Sub-bin updated.');
    }

    public function destroy(SubBin $subbin): RedirectResponse
    {
        // Legacy disabled sub-bin deletes (commented out of include/delete.php);
        // the port keeps that stance: only bins may be deleted, cascading.
        abort(405, 'Sub-bin deletion is disabled (legacy parity). Delete the bin instead.');
    }
}
