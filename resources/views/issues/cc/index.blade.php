@extends('layouts.app')

@section('title', 'Captive Consumption (CC)')

@section('content')
<div class="card">
    <div style="display:flex; justify-content:space-between; align-items:center; gap:1rem; flex-wrap:wrap">
        <h2>Captive Consumption (CC)</h2>
        <a class="btn" href="{{ route('issues.cc.create') }}">New CC transaction</a>
    </div>

    <form method="GET" class="filters" style="display:flex; gap:.75rem; align-items:end; margin:1rem 0; flex-wrap:wrap">
        <div>
            <label class="muted">Stage</label>
            <select name="stage">
                <option value="">All</option>
                <option value="open" @selected(request('stage') === 'open')>Open</option>
                <option value="posted" @selected(request('stage') === 'posted')>Posted</option>
            </select>
        </div>
        <button class="btn secondary" type="submit">Filter</button>
    </form>

    <table class="data">
        <thead>
        <tr><th>Transaction</th><th>Date</th><th>Party</th><th>Return Type</th><th>Stage</th><th></th></tr>
        </thead>
        <tbody>
        @forelse ($captives as $c)
            <tr>
                <td>{{ $c->code ? 'TCC'.$c->code : '—' }}</td>
                <td>{{ optional($c->tdate)->format('d-m-Y') }}</td>
                <td>{{ $c->party_name ?: ($c->party_id ?: '—') }}</td>
                <td>{{ $c->rettyp }}</td>
                <td><span class="badge">{{ (int) $c->ccflg === 1 ? 'Posted (stock updated)' : 'Open (awaiting final post)' }}</span></td>
                <td>
                    @if ((int) $c->ccflg === 1)
                        <a class="btn secondary" href="{{ route('issues.cc.show', $c) }}">View</a>
                    @else
                        <a class="btn secondary" href="{{ route('issues.cc.workspace', $c) }}">Open workspace</a>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="6">No transactions yet.</td></tr>
        @endforelse
        </tbody>
    </table>

    {{ $captives->links() }}
</div>
@endsection
