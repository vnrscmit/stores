<?php

namespace App\Http\Controllers;

class DashboardController extends Controller
{
    public function admin()
    {
        return view('dashboard', [
            'title' => 'Administrator',
            'modules' => $this->adminModules(),
        ]);
    }

    public function operator()
    {
        return view('dashboard', [
            'title' => 'Operator',
            'modules' => [
                ['label' => 'Issue against e-Indent', 'url' => route('issues.eindents.pending'), 'desc' => 'Issue stock against committed e-Indents'],
                ['label' => 'Issue log', 'url' => route('issues.eindents.index'), 'desc' => 'Open and posted e-Indent issues'],
                ['label' => 'Arrivals', 'url' => '#', 'desc' => 'Vendor GRN, stock transfer in, internal'],
                ['label' => 'Captive Consumption', 'url' => '#', 'desc' => 'Vendor / internal consumption notes'],
                ['label' => 'Adjustments', 'url' => '#', 'desc' => 'Discard, excess/shortage, gate movements'],
            ],
        ]);
    }

    public function eindent()
    {
        return view('dashboard', [
            'title' => 'e-Indent Raiser',
            'modules' => [
                ['label' => 'Raise e-Indent', 'url' => route('eindents.raise'), 'desc' => 'Draft and submit indents for approval'],
                ['label' => 'My Indents', 'url' => route('eindents.index'), 'desc' => 'Track drafts, approvals and rejections'],
            ],
        ]);
    }

    public function viewer()
    {
        return redirect()->route('viewer.reports.index');
    }

    private function adminModules(): array
    {
        return [
            ['label' => 'Warehouses', 'url' => route('masters.warehouses.index'), 'desc' => 'Warehouse master'],
            ['label' => 'Bins & Sub-bins (SLOC)', 'url' => route('masters.bins.index'), 'desc' => 'Bin master; new bins seed sub-bins 1-20'],
            ['label' => 'Classifications', 'url' => route('masters.classifications.index'), 'desc' => 'Classification master'],
            ['label' => 'Items', 'url' => route('masters.items.index'), 'desc' => 'Item master with UoM and reorder levels'],
            ['label' => 'Parties', 'url' => route('masters.parties.index'), 'desc' => 'Vendor, C&F, dealer and transfer parties'],
            ['label' => 'Users & Roles', 'url' => '#', 'desc' => 'Operators, e-indent raisers, viewers'],
            ['label' => 'Year Setting', 'url' => '#', 'desc' => 'Active fiscal year and year-end close'],
            ['label' => 'e-Indent Approvals', 'url' => route('eindents.approvals'), 'desc' => 'Approve or return submitted indents'],
            ['label' => 'Reports', 'url' => route('viewer.reports.index'), 'desc' => 'Stock, ledger and movement reports'],
        ];
    }
}
