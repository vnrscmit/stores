@extends('layouts.app')

@section('title', 'Reorder Level')

@section('content')
<div class="card">
    <h2>Reorder Level Report</h2>
    <p class="muted" style="margin:.25rem 0 .75rem">
        As on Date : {{ \Illuminate\Support\Facades\Date::today()->format('d-m-Y') }}
        &middot; Items with serial tracking whose quantity is at or below the reorder level.
    </p>

    <a class="btn secondary" href="{{ route('viewer.reports.reorder.export') }}" style="margin-bottom:.75rem">Export XLSX</a>

    <table class="data">
        <thead>
        <tr>
            <th>#</th>
            <th>Classification</th>
            <th>Item</th>
            <th>UoM</th>
            <th style="text-align:right">Reorder Level</th>
            <th style="text-align:right">UPS</th>
            <th style="text-align:right">Quantity</th>
            <th>Remarks</th>
        </tr>
        </thead>
        <tbody>
        @forelse ($rows as $r)
            <tr>
                <td>{{ $r['srno'] }}</td>
                <td>{{ $r['classification'] }}</td>
                <td>
                    {{ $r['item'] }}
                    @if ($r['inactive'])
                        <span style="color:#c0392b">(In-Active)</span>
                    @endif
                </td>
                <td>{{ $r['uom'] }}</td>
                <td style="text-align:right">{{ number_format($r['reorder_level'], 3) }}</td>
                <td style="text-align:right">{{ $r['ups'] }}</td>
                <td style="text-align:right">{{ number_format($r['qty'], 3) }}</td>
                <td>{{ $r['remarks'] }}</td>
            </tr>
        @empty
            <tr><td colspan="8" style="color:var(--muted)">No reorder-tracked items are at or below their reorder level.</td></tr>
        @endforelse
        </tbody>
    </table>

    <p class="muted" style="margin-top:.75rem;font-size:.8rem">
        R - Reorder Level, OR - Reorder Level Order Placed
    </p>
</div>
@endsection
