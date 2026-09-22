@extends('layouts.app')

@section('title', $meta['label'].' — queue')

@section('content')
<div class="card">
    <h2>{{ $meta['label'] }}</h2>

    <div class="filters" style="display:flex; gap:1rem; align-items:center; margin:.5rem 0 1rem">
        <a class="btn" href="{{ route('arrivals.'.$type.'.create') }}">New {{ $meta['label'] }}</a>
        <div>
            <label>Stage</label>
            <select onchange="window.location = this.value ? `{{ route('arrivals.'.$type.'.index') }}?stage=${this.value}` : '{{ route('arrivals.'.$type.'.index') }}'">
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
            <th>@if ($type === 'vendor') Vendor @elseif ($type === 'stocktr') STN No @else Stage @endif</th>
            @if ($type === 'vendor')<th>D.C./Inv. No</th>@endif
            <th>Stage</th>
            <th></th>
        </tr>
        </thead>
        <tbody>
        @forelse ($arrivals as $arrival)
            <tr>
                <td><strong>{{ \App\Support\ArrivalNumbering::transactionId($arrival, $type) }}</strong></td>
                <td>{{ optional($arrival->arrival_date)->format('d-m-Y') }}</td>
                <td>
                    @if ($type === 'vendor')
                        {{ \App\Models\Party::find($arrival->party_id)?->business_name ?? '—' }}
                    @elseif ($type === 'stocktr')
                        {{ $arrival->stnno }}
                    @else
                        {{ $arrival->stageret }}
                    @endif
                </td>
                @if ($type === 'vendor')<td>{{ $arrival->dcno }}</td>@endif
                <td><span class="badge">{{ \App\Support\ArrivalStatus::LABELS[$arrival->status] ?? $arrival->status }}</span></td>
                <td>
                    <a class="btn secondary" href="{{ route('arrivals.'.$type.'.'.($arrival->isPosted() ? 'show' : 'workspace'), $arrival) }}">
                        {{ $arrival->isPosted() ? 'View' : 'Open workspace' }}
                    </a>
                </td>
            </tr>
        @empty
            <tr><td colspan="6" class="muted">No transactions yet.</td></tr>
        @endforelse
        </tbody>
    </table>

    {{ $arrivals->links() }}
</div>
@endsection
