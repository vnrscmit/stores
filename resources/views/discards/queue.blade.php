@extends('layouts.app')

@section('title', 'Material Discard — queue')

@section('content')
<div class="card">
    <h2>Material Discard (MD)</h2>

    <div class="filters" style="display:flex; gap:1rem; align-items:center; margin:.5rem 0 1rem; flex-wrap:wrap">
        <a class="btn" href="{{ route('discards.create') }}">New Material Discard</a>
        <form method="GET" action="{{ route('discards.index') }}" style="display:flex; gap:.5rem; align-items:end">
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
            <th>Transaction Id</th>
            <th>Discard Date</th>
            <th>Party</th>
            <th>Discard Inst. Ref. No.</th>
            <th></th>
        </tr>
        </thead>
        <tbody>
        @forelse ($discards as $discard)
            <tr>
                <td>
                    <strong>{{ \App\Support\DocumentNumber::pretty('discard', (int) $discard->dd_code, (string) $discard->yearcode) }}</strong>
                </td>
                <td>{{ $discard->tdate ? \Illuminate\Support\Carbon::parse($discard->tdate)->format('d-m-Y') : '—' }}</td>
                <td>{{ $discard->party_name }}</td>
                <td>{{ $discard->drno }}</td>
                <td><a class="btn secondary" href="{{ route('discards.show', $discard) }}">View</a></td>
            </tr>
        @empty
            <tr><td colspan="5" class="muted">No posted discards yet.</td></tr>
        @endforelse
        </tbody>
    </table>

    {{ $discards->links() }}
</div>
@endsection
