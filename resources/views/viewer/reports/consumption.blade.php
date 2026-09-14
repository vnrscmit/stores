@extends('layouts.app')

@section('title', 'Consumption (Item-wise)')

@section('content')
<div class="card">
    <h2>Consumption (Item-wise)</h2>
    <form method="GET" class="filters">
        <div><label>From</label><input type="date" name="from" value="{{ $from }}"></div>
        <div><label>To</label><input type="date" name="to" value="{{ $to }}"></div>
        <div><label>Classification ID</label><input type="number" name="classification_id" value="{{ $classificationId }}" style="width:8rem"></div>
        <div><label>Item ID</label><input type="number" name="item_id" value="{{ $itemId }}" style="width:8rem"></div>
        <button class="btn" type="submit">Apply</button>
        <a class="btn secondary" href="{{ route('viewer.reports.consumption.export', request()->query()) }}">Export XLSX</a>
    </form>

    <table class="data">
        <thead>
        <tr>
            <th>Classification</th>
            <th>Item</th>
            <th>UoM</th>
            <th style="text-align:right">Issue UPS</th>
            <th style="text-align:right">Issue Qty</th>
            <th style="text-align:right">Internal Return UPS</th>
            <th style="text-align:right">Internal Return Qty</th>
            <th style="text-align:right">Issue UPS</th>
            <th style="text-align:right">Issue Qty</th>
            <th style="text-align:right">Internal Return UPS</th>
            <th style="text-align:right">Internal Return Qty</th>
            <th style="text-align:right">Used Qty UPS</th>                <th style="text-align:right">Used Qty</th>
            </tr>
        </thead>
        <tbody>
        @forelse ($rows as $r)
            @php($p = $r['perticulars'] ?? '')
            <tr>
                <td>{{ $r['classification'] }}</td>
                <td>{{ $r['item'] }}</td>
                <td>{{ $r['uom'] }}</td>
                <td style="text-align:right">{{ $r['issue_ups'] }}</td>
                <td style="text-align:right">{{ number_format($r['issue_qty'], 3) }}</td>
                <td style="text-align:right">{{ $r['internal_return_ups'] }}</td>
                <td style="text-align:right">{{ number_format($r['internal_return_qty'], 3) }}</td>
                <td style="text-align:right">{{ $r['used_qty_ups'] }}</td>
                <td style="text-align:right">{{ number_format($r['used_qty'], 3) }}</td>
                @if($p)
                <td colspan="2" style="text-align:left;color:var(--muted);font-size:.8rem">{{ $p }}</td>
                @endif
            </tr>
        @empty
            <tr><td colspan="8" style="color:var(--muted)">No consumption rows in the selected period.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
