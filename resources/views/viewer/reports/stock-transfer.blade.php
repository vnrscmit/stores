@extends('layouts.app')

@section('title', 'Stock Transfer')

@section('content')
<div class="card">
    <h2>Stock Transfer (Party-wise)</h2>
    <form method="GET" class="filters">
        <div><label>From</label><input type="date" name="from" value="{{ $from }}"></div>
        <div><label>To</label><input type="date" name="to" value="{{ $to }}"></div>
        <div><label>Party ID (0 = all)</label><input type="number" name="party_id" value="{{ $partyId }}" style="width:8rem"></div>
        <button class="btn" type="submit">Apply</button>
        <a class="btn secondary" href="{{ route('viewer.reports.stock-transfer.export', request()->query()) }}">Export XLSX</a>
    </form>

    <table class="data">
        <thead>
        <tr>
            <th>Date</th><th>Type</th><th>Sub-type</th><th>Doc #</th><th>Item</th>
            <th style="text-align:right">UPS</th><th style="text-align:right">Qty</th>
        </tr>
        </thead>
        <tbody>
        @forelse ($rows as $r)
            <tr>
                <td>{{ $r['date'] }}</td>
                <td>{{ $r['type'] }}</td>
                <td>{{ $r['subtype'] }}</td>
                <td>{{ $r['doc'] }}</td>
                <td>{{ $r['item'] }}</td>
                <td style="text-align:right">{{ $r['ups'] }}</td>
                <td style="text-align:right">{{ number_format($r['qty'], 3) }}</td>
            </tr>
        @empty
            <tr><td colspan="7" style="color:var(--muted)">No movements in the selected period.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
