@extends('layouts.app')

@section('title', 'Gate movements — queue')

@section('content')
<div class="card">
    <h2>{{ $direction === 'g2d' ? 'Good → Damage (G2D)' : 'Damage → Good (D2G)' }} conversions</h2>

    <div class="filters" style="display:flex; gap:1rem; align-items:center; margin:.5rem 0 1rem; flex-wrap:wrap">
        <a class="btn" href="{{ $direction === 'g2d' ? route('gatemovements.create') : route('gatemovements.create-d2g') }}">New {{ $direction === 'g2d' ? 'Good → Damage' : 'Damage → Good' }}</a>
        <a class="btn secondary" href="{{ $direction === 'g2d' ? route('gatemovements.index-d2g') : route('gatemovements.index') }}">
            Switch to {{ $direction === 'g2d' ? 'Damage → Good' : 'Good → Damage' }}
        </a>
        <form method="GET" action="{{ $direction === 'g2d' ? route('gatemovements.index') : route('gatemovements.index-d2g') }}"
              style="display:flex; gap:.5rem; align-items:end">
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
            <th>Item</th>
            <th></th>
        </tr>
        </thead>
        <tbody>
        @forelse ($documents as $document)
            <tr>
                <td>
                    <strong>
                        @if ($direction === 'g2d')
                            {{ \App\Support\DocumentNumber::pretty('gtod', (int) $document->gcode, (string) $document->yearcode) }}
                        @else
                            {{ \App\Support\DocumentNumber::pretty('dtog', (int) $document->dcode, (string) $document->yearcode) }}
                        @endif
                    </strong>
                </td>
                <td>{{ $document->date ? \Illuminate\Support\Carbon::parse($document->date)->format('d-m-Y') : '—' }}</td>
                <td>{{ $document->items_id }} ({{ $document->uom }})</td>
                <td>
                    <a class="btn secondary"
                       href="{{ $direction === 'g2d' ? route('gatemovements.show', $document) : route('gatemovements.show-d2g', $document) }}">View</a>
                </td>
            </tr>
        @empty
            <tr><td colspan="4" class="muted">No posted {{ $direction === 'g2d' ? 'G2D' : 'D2G' }} documents yet.</td></tr>
        @endforelse
        </tbody>
    </table>

    {{ $documents->links() }}
</div>
@endsection
