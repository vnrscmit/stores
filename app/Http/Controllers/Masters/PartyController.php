<?php

namespace App\Http\Controllers\Masters;

use App\Http\Controllers\Controller;
use App\Http\Requests\Masters\PartyRequest;
use App\Models\Party;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Party master (legacy add/edit_party_master.php + party_Masterhome.php).
 * Parity: business_name unique; category list; India requires a state
 * (enforced client-side by legacy, server-side here); hard delete.
 */
class PartyController extends Controller
{
    private const CATEGORIES = ['Vendor', 'C&F', 'Dealers', 'Stock Transfer', 'Internal Return'];

    public function index(): View
    {
        $q = trim((string) request('q'));
        $category = request('classification');

        $parties = Party::query()
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($inner) use ($q) {
                    $inner->where('business_name', 'like', "%{$q}%")
                        ->orWhere('contact', 'like', "%{$q}%")
                        ->orWhere('city', 'like', "%{$q}%");
                });
            })
            ->when($category, fn ($query) => $query->where('classification', $category))
            ->orderBy('business_name')
            ->paginate(15)
            ->withQueryString();

        return view('masters.parties.index', [
            'parties' => $parties,
            'q' => $q,
            'category' => $category,
            'categories' => self::CATEGORIES,
        ]);
    }

    public function create(): View
    {
        return view('masters.parties.create', ['categories' => self::CATEGORIES]);
    }

    public function store(PartyRequest $request): RedirectResponse
    {
        $party = Party::create($this->payload($request));

        Audit::changed('masters.party', 'create', $party);

        return redirect()->route('masters.parties.index')->with('status', 'Party created.');
    }

    public function edit(Party $party): View
    {
        return view('masters.parties.edit', [
            'party' => $party,
            'categories' => self::CATEGORIES,
        ]);
    }

    public function update(PartyRequest $request, Party $party): RedirectResponse
    {
        $party->update($this->payload($request));

        Audit::changed('masters.party', 'update', $party);

        return redirect()->route('masters.parties.index')->with('status', 'Party updated.');
    }

    public function destroy(Party $party): RedirectResponse
    {
        $before = ['p_id' => $party->p_id, 'business_name' => $party->business_name];

        $party->delete();

        Audit::log('masters.party', 'delete', $party, $before);

        return redirect()->route('masters.parties.index')->with('status', 'Party deleted.');
    }

    private function payload(PartyRequest $request): array
    {
        return $request->validated();
    }
}
