<?php

namespace App\Http\Controllers\Masters;

use App\Http\Controllers\Controller;
use App\Http\Requests\Masters\ClassificationRequest;
use App\Models\Classification;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Classification master (legacy add/edit_classification.php +
 * home_classification.php). Parity: classification name unique; hard delete.
 */
class ClassificationController extends Controller
{
    public function index(): View
    {
        $q = trim((string) request('q'));

        $classifications = Classification::query()
            ->when($q !== '', fn ($query) => $query->where('classification', 'like', "%{$q}%"))
            ->withCount('items')
            ->orderBy('classification')
            ->paginate(15)
            ->withQueryString();

        return view('masters.classifications.index', [
            'classifications' => $classifications,
            'q' => $q,
        ]);
    }

    public function create(): View
    {
        return view('masters.classifications.create');
    }

    public function store(ClassificationRequest $request): RedirectResponse
    {
        $classification = Classification::create([
            'classification' => trim($request->input('classification')),
        ]);

        Audit::changed('masters.classification', 'create', $classification);

        return redirect()->route('masters.classifications.index')->with('status', 'Classification created.');
    }

    public function edit(Classification $classification): View
    {
        return view('masters.classifications.edit', ['classification' => $classification]);
    }

    public function update(ClassificationRequest $request, Classification $classification): RedirectResponse
    {
        $classification->update(['classification' => trim($request->input('classification'))]);

        Audit::changed('masters.classification', 'update', $classification);

        return redirect()->route('masters.classifications.index')->with('status', 'Classification updated.');
    }

    public function destroy(Classification $classification): RedirectResponse
    {
        $before = [
            'classification_id' => $classification->classification_id,
            'classification' => $classification->classification,
        ];

        $classification->delete();

        Audit::log('masters.classification', 'delete', $classification, $before);

        return redirect()->route('masters.classifications.index')->with('status', 'Classification deleted.');
    }
}
