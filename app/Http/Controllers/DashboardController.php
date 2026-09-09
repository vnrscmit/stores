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
                ['label' => 'Issue against e-Indent', 'url' => '#', 'desc' => 'Issue stock against committed e-Indents'],
                ['label' => 'Issues', 'url' => '#', 'desc' => 'Physical indent, MRTV, stock transfer, internal CC'],
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
                ['label' => 'Raise e-Indent', 'url' => '#', 'desc' => 'Draft and commit indents'],
                ['label' => 'My Indents', 'url' => '#', 'desc' => 'Track submitted indents'],
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
            ['label' => 'Masters', 'url' => '#', 'desc' => 'Warehouse, bins, classification, items, parties'],
            ['label' => 'Users & Roles', 'url' => '#', 'desc' => 'Operators, e-indent raisers, viewers'],
            ['label' => 'Year Setting', 'url' => '#', 'desc' => 'Active fiscal year and year-end close'],
            ['label' => 'Reports', 'url' => route('viewer.reports.index'), 'desc' => 'Stock, ledger and movement reports'],
        ];
    }
}
