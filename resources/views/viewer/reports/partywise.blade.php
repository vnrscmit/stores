@extends('layouts.app')

@section('title', 'Party-wise Period')

@section('content')
<div class="card">
    <h2>Party-wise Period Report</h2>
    <form method="GET" class="filters">
        <div><label>Party ID</label><input type="number" name="party_id" value="{{ $partyId }}" style="width:8rem" placeholder="0 = all"></div>
        <div><label>From</label><input type="date" name="from" value="{{ $from }}"></div>
        <div><label>To</label><input type="date" name="to" value="{{ $to }}"></div>
        <div><label>Classification ID</label><input type="number" name="classification_id" value="{{ $classificationId }}" style="width:8rem"></div>
        <div><label>Item ID</label><input type="number" name="item_id" value="{{ $itemId }}" style="width:8rem"></div>
        <button class="btn" type="submit">Apply</button>
        <a class="btn secondary" href="{{ route('viewer.reports.partywise.export', request()->query()) }}">Export XLSX</a>
    </form>

    <p class="muted" style="margin:.75rem 0 .25rem">
        Party: {{ $partyName }} &middot; Period: {{ $from }} to {{ $to }}
    </p>

    <h3 style="font-size:.95rem;margin:1rem 0 .5rem">Period totals</h3>
    <table class="data">
        <thead>
        <tr>
            <th style="text-align:right">DC UPS</th>
            <th style="text-align:right">DC Qty</th>
            <th style="text-align:right">Good UPS</th>
            <th style="text-align:right">Good Qty</th>
            <th style="text-align:right">Damage UPS</th>
            <th style="text-align:right">Damage Qty</th>
            <th style="text-align:right">Excess</th>
            <th style="text-align:right">Shortage</th>
            <th style="text-align:right">Closing UPS</th>
            <th style="text-align:right">Closing Qty</th>
        </tr>
        </thead>
        <tbody>
        <tr>
            <td style="text-align:right">{{ $totals['dc_ups'] }}</td>
            <td style="text-align:right">{{ number_format($totals['dc_qty'], 3) }}</td>
            <td style="text-align:right">{{ $totals['good_ups'] }}</td>
            <td style="text-align:right">{{ number_format($totals['good_qty'], 3) }}</td>
            <td style="text-align:right">{{ $totals['damage_ups'] }}</td>
            <td style="text-align:right">{{ number_format($totals['damage_qty'], 3) }}</td>
            <td style="text-align:right">{{ number_format($totals['excess'], 3) }}</td>
            <td style="text-align:right">{{ number_format($totals['shortage'], 3) }}</td>
            <td style="text-align:right">{{ $totals['closing_ups'] }}</td>
            <td style="text-align:right">{{ number_format($totals['closing_qty'], 3) }}</td>
        </tr>
        </tbody>
    </table>

    <h3 style="font-size:.95rem;margin:1rem 0 .5rem">Movements</h3>
    <table class="data">
        <thead>
        <tr>
            <th rowspan="2">Date</th>
            <th rowspan="2">Particulars</th>
            <th rowspan="2">Item</th>
            <th colspan="2">Opening</th>
            <th colspan="2">DC</th>
            <th colspan="2">Good</th>
            <th colspan="2">Arrival Damage</th>
            <th colspan="2">Internal Damage</th>
            <th rowspan="2" style="text-align:right">Excess</th>
            <th rowspan="2" style="text-align:right">Shortage</th>
            <th colspan="2">Net</th>
            <th colspan="2">Issue</th>
            <th colspan="2">Balance</th>
        </tr>
        <tr>
            <th style="text-align:right">UPS</th><th style="text-align:right">Qty</th>
            <th style="text-align:right">UPS</th><th style="text-align:right">Qty</th>
            <th style="text-align:right">UPS</th><th style="text-align:right">Qty</th>
            <th style="text-align:right">UPS</th><th style="text-align:right">Qty</th>
            <th style="text-align:right">UPS</th><th style="text-align:right">Qty</th>
            <th style="text-align:right">UPS</th><th style="text-align:right">Qty</th>
            <th style="text-align:right">UPS</th><th style="text-align:right">Qty</th>
            <th style="text-align:right">UPS</th><th style="text-align:right">Qty</th>
        </tr>
        </thead>
        <tbody>
        @forelse ($rows as $r)
            <tr>
                <td>{{ $r['date'] }}</td>
                <td>{{ $r['particulars'] }}</td>
                <td>{{ $r['item'] }}</td>
                <td style="text-align:right">{{ $r['opening_ups'] }}</td>
                <td style="text-align:right">{{ number_format($r['opening_qty'], 3) }}</td>
                <td style="text-align:right">{{ $r['dc_ups'] }}</td>
                <td style="text-align:right">{{ number_format($r['dc_qty'], 3) }}</td>
                <td style="text-align:right">{{ $r['good_ups'] }}</td>
                <td style="text-align:right">{{ number_format($r['good_qty'], 3) }}</td>
                <td style="text-align:right">{{ $r['arrival_damage_ups'] }}</td>
                <td style="text-align:right">{{ number_format($r['arrival_damage_qty'], 3) }}</td>
                <td style="text-align:right">{{ $r['internal_damage_ups'] }}</td>
                <td style="text-align:right">{{ number_format($r['internal_damage_qty'], 3) }}</td>
                <td style="text-align:right">{{ number_format($r['excess'], 3) }}</td>
                <td style="text-align:right">{{ number_format($r['shortage'], 3) }}</td>
                <td style="text-align:right">{{ $r['net_ups'] }}</td>
                <td style="text-align:right">{{ number_format($r['net_qty'], 3) }}</td>
                <td style="text-align:right">{{ $r['issue_ups'] }}</td>
                <td style="text-align:right">{{ number_format($r['issue_qty'], 3) }}</td>
                <td style="text-align:right">{{ $r['balance_ups'] }}</td>
                <td style="text-align:right">{{ number_format($r['balance_qty'], 3) }}</td>
            </tr>
        @empty
            <tr><td colspan="21" style="color:var(--muted)">No party-ledger movements in the selected period.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
