@extends('layouts.app')

@section('title', 'Stock On Hand')

@section('content')
<div class="card">
    <h2>Stock On Hand (Good)</h2>
    <form method="GET" class="filters">
        <div>
            <label>As-of date</label>
            <input type="date" name="as_of" value="{{ $asOf }}">
        </div>
        <div>
            <label>Classification ID</label>
            <input type="number" name="classification_id" value="{{ $classificationId }}" min="1" style="width:9rem">
        </div>
        <button class="btn" type="submit">Apply</button>
        <a class="btn secondary" href="{{ route('viewer.reports.stock-on-hand.export', request()->query()) }}">Export XLSX</a>
    </form>

    <table class="data">
        <thead>
        <tr>
            <th>Classification</th><th>Item</th><th>UoM</th>
            <th>Warehouse</th><th>Bin</th><th>Sub-bin</th>
            <th style="text-align:right">UPS</th><th style="text-align:right">Qty</th>
        </tr>
        </thead>
        <tbody>
        @forelse ($rows as $r)
            <tr>
                <td>{{ $r['classification'] }}</td>
                <td>{{ $r['item'] }}</td>
                <td>{{ $r['uom'] }}</td>
                <td>{{ $r['warehouse'] }}</td>
                <td>{{ $r['bin'] }}</td>
                <td>{{ $r['subbin'] }}</td>
                <td style="text-align:right">{{ $r['ups'] }}</td>
                <td style="text-align:right">{{ number_format($r['qty'], 3) }}</td>
            </tr>
        @empty
            <tr><td colspan="8" style="color:var(--muted)">No stock found for the selected filters.</td></tr>
        @endforelse
        </tbody>
    </table>
    @if (count($rows) >= 500)
        <p style="font-size:.8rem;color:var(--muted)">Showing first 500 rows on screen — use the export for the full dataset.</p>
    @endif
</div>
@endsection
