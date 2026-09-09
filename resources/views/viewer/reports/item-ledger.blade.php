@extends('layouts.app')

@section('title', 'Stores Item Ledger')

@section('content')
<div class="card">
    <h2>Stores Item Ledger</h2>
    <form method="GET" class="filters">
        <div><label>From</label><input type="date" name="from" value="{{ $from }}"></div>
        <div><label>To</label><input type="date" name="to" value="{{ $to }}"></div>
        <div><label>Classification ID</label><input type="number" name="classification_id" value="{{ $classificationId }}" style="width:8rem"></div>
        <div><label>Item ID</label><input type="number" name="item_id" value="{{ $itemId }}" style="width:8rem"></div>
        <button class="btn" type="submit">Apply</button>
        <a class="btn secondary" href="{{ route('viewer.reports.item-ledger.export', request()->query()) }}">Export XLSX</a>
    </form>

    <table class="data">
        <thead>
        <tr>
            <th>Date</th><th>Type</th><th>Sub-type</th><th>Doc #</th>
            <th style="text-align:right">UPS</th><th style="text-align:right">Qty</th>
            <th style="text-align:right">Bal UPS</th><th style="text-align:right">Bal Qty</th>
        </tr>
        </thead>
        <tbody>
        @forelse ($rows as $r)
            <tr>
                <td>{{ $r['date'] }}</td>
                <td>{{ $r['type'] }}</td>
                <td>{{ $r['subtype'] }}</td>
                <td>{{ $r['doc'] }}</td>
                <td style="text-align:right">{{ $r['ups'] }}</td>
                <td style="text-align:right">{{ number_format($r['qty'], 3) }}</td>
                <td style="text-align:right">{{ $r['balups'] }}</td>
                <td style="text-align:right">{{ number_format($r['balqty'], 3) }}</td>
            </tr>
        @empty
            <tr><td colspan="8" style="color:var(--muted)">No movements in the selected period.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
