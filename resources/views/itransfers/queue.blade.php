@extends('layouts.app')

@section('title', 'Inter Item Transfer — queue')

@section('content')
<div class="card">
    <h2>Inter Item Transfer (ITI / ITA)</h2>

    <div class="filters" style="display:flex; gap:1rem; align-items:center; margin:.5rem 0 1rem">
        <a class="btn" href="{{ route('itransfers.create') }}">New Inter Item Transfer</a>
        <div>
            <label>Stage</label>
            <select onchange="window.location = this.value ? `{{ route('itransfers.index') }}?stage=${this.value}` : '{{ route('itransfers.index') }}'">
                <option value="">All</option>
                <option value="open" @selected(request('stage') === 'open')>Open</option>
                <option value="posted" @selected(request('stage') === 'posted')>Posted</option>
            </select>
        </div>
    </div>

    <table class="data">
        <thead>
        <tr>
            <th>Transaction Id</th>
            <th>Date</th>
            <th>Source Item</th>
            <th>Stage</th>
            <th></th>
        </tr>
        </thead>
        <tbody>
        @forelse ($transfers as $transfer)
            @php($sourceName = \App\Models\Item::find($transfer->items_id_from)?->stores_item ?? '—')
            <tr>
                <td>
                    @if ((int) $transfer->iitrflg === 1 && $transfer->iitr_code)
                        <strong>{{ \App\Support\DocumentNumber::pretty('iitr', (int) $transfer->iitr_code, (string) $transfer->yearcode) }}</strong>
                    @else
                        <span class="muted">TIT{{ $transfer->iitr_id }}/{{ $transfer->yearcode }}</span>
                    @endif
                </td>
                <td>{{ $transfer->tdate ? \Illuminate\Support\Carbon::parse($transfer->tdate)->format('d-m-Y') : '—' }}</td>
                <td>{{ $sourceName }}</td>
                <td><span class="badge">{{ \App\Support\ArrivalStatus::LABELS[$transfer->status] ?? $transfer->status }}</span></td>
                <td>
                    <a class="btn secondary" href="{{ route((int) $transfer->iitrflg === 1 ? 'itransfers.show' : 'itransfers.workspace', $transfer) }}">
                        {{ (int) $transfer->iitrflg === 1 ? 'View' : 'Open workspace' }}
                    </a>
                </td>
            </tr>
        @empty
            <tr><td colspan="5" class="muted">No transactions yet.</td></tr>
        @endforelse
        </tbody>
    </table>

    {{ $transfers->links() }}
</div>
@endsection
