@extends('layouts.app')

@section('title', 'Excess / Shortage — queue')

@section('content')
<div class="card">
    <h2>Excess / Shortage adjustments (ES)</h2>

    <div class="filters" style="display:flex; gap:1rem; align-items:center; margin:.5rem 0 1rem; flex-wrap:wrap">
        <a class="btn" href="{{ route('exshorts.create') }}">New Excess / Shortage</a>
        <form method="GET" action="{{ route('exshorts.index') }}" style="display:flex; gap:.5rem; align-items:end">
            <div>
                <label>From</label>
                <input type="date" name="from" value="{{ request('from') }}">
            </div>
            <div>
                <label>To</label>
                <input type="date" name="to" value="{{ request('to') }}">
            </div>
            <button class="btn secondary" type="submit">Search</button>
        </form>
    </div>

    <table class="data">
        <thead>
        <tr>
            <th>Committed Serial</th>
            <th>Date</th>
            <th>Ledger</th>
            <th>Adjustment UPS</th>
            <th>Adjustment Qty</th>
            <th></th>
        </tr>
        </thead>
        <tbody>
        @forelse ($excesses as $excess)
            <tr>
                <td>
                    <strong>{{ \App\Support\DocumentNumber::pretty('excess', (int) $excess->escode, (string) $excess->yearcode) }}</strong>
                </td>
                <td>{{ $excess->tdate ? \Illuminate\Support\Carbon::parse($excess->tdate)->format('d-m-Y') : '—' }}</td>
                <td>{{ $excess->typ === 'good' ? 'Good' : 'Damage' }}</td>
                <td>{{ $excess->ups }}</td>
                <td>{{ $excess->qty }}</td>
                <td><a class="btn secondary" href="{{ route('exshorts.show', $excess) }}">View</a></td>
            </tr>
        @empty
            <tr><td colspan="6" class="muted">No posted excess/shortage documents yet.</td></tr>
        @endforelse
        </tbody>
    </table>

    {{ $excesses->links() }}
</div>
@endsection
