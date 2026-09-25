@extends('layouts.app')

@section('title', 'Excess / Shortage — bin status sheet')

@section('content')
<div class="card">
    <h2>Excess / Shortage — bin status sheet</h2>

    <table class="data" style="margin-bottom:1rem">
        <tr>
            <th>Committed Serial</th>
            <td><strong>{{ \App\Support\DocumentNumber::pretty('excess', (int) $excess->escode, (string) $excess->yearcode) }}</strong></td>
            <th>Date</th>
            <td>{{ $excess->tdate ? \Illuminate\Support\Carbon::parse($excess->tdate)->format('d-m-Y') : '—' }}</td>
        </tr>
        <tr>
            <th>Ledger</th>
            <td>{{ $excess->typ === 'good' ? 'Good stock (Bin Status Sheet)' : 'Damage stock (Bin Status Sheet)' }}</td>
            <th>Adjustment totals</th>
            <td>{{ $excess->ups }} UPS / {{ $excess->qty }} {{ $excess->uom }}</td>
        </tr>
        @if ($excess->remarks)
            <tr><th>Remarks</th><td colspan="3">{{ $excess->remarks }}</td></tr>
        @endif
    </table>

    <h3 style="font-size:.95rem">
        {{ $excess->typ === 'good' ? 'Bin Status Sheet — Good' : 'Bin Status Sheet — Damage' }}
    </h3>
    <table class="data">
        <thead>
        <tr>
            <th>#</th>
            <th>SLOC (wh / bin / sub-bin)</th>
            <th>Excess UPS</th>
            <th>Excess Qty</th>
            <th>Shortage UPS</th>
            <th>Shortage Qty</th>
            <th>Post Balance</th>
        </tr>
        </thead>
        <tbody>
        @forelse ($lines as $index => $line)
            <tr>
                <td>{{ $index + 1 }}</td>
                <td>{{ $line['whid'] }} / {{ $line['binid'] }} / {{ $line['subbinid'] }}</td>
                <td>{{ $line['upsex'] }}</td>
                <td>{{ $line['qtyex'] }}</td>
                <td>{{ $line['upssh'] }}</td>
                <td>{{ $line['qtysh'] }}</td>
                <td>{{ $line['balups'] }} / {{ $line['balqty'] }}</td>
            </tr>
        @empty
            <tr><td colspan="7" class="muted">No rows.</td></tr>
        @endforelse
        </tbody>
    </table>

    <div style="margin-top:1rem">
        <a class="btn secondary" href="{{ route('exshorts.index') }}">Back to queue</a>
    </div>
</div>
@endsection
