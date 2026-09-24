@extends('layouts.app')

@section('title', 'Inter Item Transfer — '.\App\Support\DocumentNumber::pretty('iitr', (int) $transfer->iitr_code, (string) $transfer->yearcode))

@section('content')
<div class="card">
    <h2>
        Inter Item Transfer —
        @if ((int) $transfer->iitrflg === 1 && $transfer->iitr_code)
            {{ \App\Support\DocumentNumber::pretty('iitr', (int) $transfer->iitr_code, (string) $transfer->yearcode) }}
        @else
            TIT{{ $transfer->iitr_id }}/{{ $transfer->yearcode }}
        @endif
    </h2>

    <table class="data" style="margin-bottom:1rem">
        <tr>
            <th>Transaction Id</th>
            <td><strong>TIT{{ $transfer->iitr_id }}/{{ $transfer->yearcode }}</strong></td>
            <th>Date</th>
            <td>{{ $transfer->tdate ? \Illuminate\Support\Carbon::parse($transfer->tdate)->format('d-m-Y') : '—' }}</td>
        </tr>
        <tr>
            <th>Source Item</th>
            <td>{{ \App\Models\Item::find($transfer->items_id_from)?->stores_item ?? '—' }}</td>
            <th>UoM</th>
            <td>{{ $transfer->uom_from }}</td>
        </tr>
        <tr>
            <th>Type</th>
            <td>{{ ucfirst((string) $transfer->typ) }}</td>
            <th>Stage</th>
            <td><span class="badge">{{ \App\Support\ArrivalStatus::LABELS[$transfer->status] ?? $transfer->status }}</span></td>
        </tr>
        @if ($transfer->remarks)
            <tr><th>Remarks</th><td colspan="3">{{ $transfer->remarks }}</td></tr>
        @endif
    </table>

    <table class="data">
        <thead>
        <tr>
            <th>#</th>
            <th colspan="3">Stock in Hand</th>
            <th colspan="4">Transferred to</th>
        </tr>
        <tr>
            <th></th>
            <th>Item</th><th>SLOC</th><th>Bal</th>
            <th>Item</th><th>SLOC</th><th>UPS / Qty</th>
        </tr>
        </thead>
        <tbody>
        @forelse ($groups as $group)
            @foreach ($group['lines'] as $line)
                <tr>
                    @if ($loop->parent->first)
                        <td rowspan="{{ count($group['lines']) }}">{{ $loop->parent->iteration }}</td>
                        <td rowspan="{{ count($group['lines']) }}">{{ $group['source']['item_name'] ?? '—' }}</td>
                        <td rowspan="{{ count($group['lines']) }}">
                            {{ $group['source']['whid'] ?? '—' }} / {{ $group['source']['binid'] ?? '—' }} / {{ $group['source']['subbinid'] ?? '—' }}
                        </td>
                        <td rowspan="{{ count($group['lines']) }}">{{ $group['source']['ups'] ?? '—' }} / {{ $group['source']['qty'] ?? '—' }}</td>
                    @endif
                    <td>{{ $line['item_name'] }}</td>
                    <td>{{ $line['whid'] }} / {{ $line['binid'] }} / {{ $line['subbinid'] }}</td>
                    <td>{{ $line['ups_to'] }} / {{ $line['qty_to'] }}</td>
                </tr>
            @endforeach
        @empty
            <tr><td colspan="7" class="muted">No lines.</td></tr>
        @endforelse
        </tbody>
    </table>

    <a class="btn secondary" href="{{ route('itransfers.index') }}" style="margin-top:1rem">Back to list</a>
</div>
@endsection
