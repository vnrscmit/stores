@extends('layouts.app')

@section('title', 'Excess / Shortage — workspace')

@section('content')
<div class="card">
    <h2>Excess / Shortage — TES{{ $excess->code }}/{{ $excess->yearcode }}</h2>

    <table class="data" style="margin-bottom:1rem">
        <tr>
            <th>Document</th>
            <td><strong>TES{{ $excess->code }}/{{ $excess->yearcode }}</strong></td>
            <th>Date</th>
            <td>{{ $excess->tdate ? \Illuminate\Support\Carbon::parse($excess->tdate)->format('d-m-Y') : '—' }}</td>
        </tr>
        <tr>
            <th>Ledger</th>
            <td>{{ $excess->typ === 'good' ? 'Good stock' : 'Damage stock' }}</td>
            <th>Stage</th>
            <td><span class="badge">{{ (int) $excess->esflg === 1 ? 'Posted' : 'Open' }}</span></td>
        </tr>
        <tr>
            <th>Adjustment totals</th>
            <td>{{ $excess->ups }} UPS / {{ $excess->qty }} {{ $excess->uom }}</td>
            <th>Committed Serial</th>
            <td>{{ $excess->escode ? \App\Support\DocumentNumber::pretty('excess', (int) $excess->escode, (string) $excess->yearcode) : '—' }}</td>
        </tr>
        @if ($excess->remarks)
            <tr><th>Remarks</th><td colspan="3">{{ $excess->remarks }}</td></tr>
        @endif
    </table>

    <h3 style="font-size:.95rem">Adjustment rows</h3>
    <table class="data">
        <thead>
        <tr>
            <th>SLOC (wh / bin / sub-bin)</th>
            <th>Excess UPS</th>
            <th>Excess Qty</th>
            <th>Shortage UPS</th>
            <th>Shortage Qty</th>
            <th>Post Balance</th>
            <th>Current Balance</th>
        </tr>
        </thead>
        <tbody>
        @forelse ($lines as $line)
            <tr>
                <td>{{ $line['whid'] }} / {{ $line['binid'] }} / {{ $line['subbinid'] }}</td>
                <td>{{ $line['upsex'] }}</td>
                <td>{{ $line['qtyex'] }}</td>
                <td>{{ $line['upssh'] }}</td>
                <td>{{ $line['qtysh'] }}</td>
                <td>{{ $line['balups'] }} / {{ $line['balqty'] }}</td>
                <td>
                    @if ($line['current_balance'] === null)
                        <span class="muted">row gone</span>
                    @else
                        {{ $line['current_balance']['ups'] }} / {{ $line['current_balance']['qty'] }}
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="7" class="muted">No rows yet.</td></tr>
        @endforelse
        </tbody>
    </table>

    @if ((int) $excess->esflg !== 1)
        <div style="margin-top:1rem; display:flex; gap:.5rem; align-items:center">
            <form method="POST" action="{{ route('exshorts.post', $excess) }}">
                @csrf
                <button class="btn" type="submit">Post adjustment</button>
            </form>
            <a class="btn secondary" href="{{ route('exshorts.create') }}">Add / replace rows</a>
        </div>
    @endif
</div>
@endsection
