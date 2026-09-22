@extends('layouts.app')

@section('title', $meta['label'].' — '.\App\Support\ArrivalNumbering::transactionId($arrival, $type))

@section('content')
<div class="card">
    <h2>{{ $meta['label'] }} — {{ \App\Support\ArrivalNumbering::transactionId($arrival, $type) }}</h2>

    <table class="data" style="margin-bottom:1rem">
        <tr>
            <th>Transaction Id</th>
            <td><strong>{{ \App\Support\ArrivalNumbering::transactionId($arrival, $type) }}</strong></td>
            <th>Date</th>
            <td>{{ optional($arrival->arrival_date)->format('d-m-Y') }}</td>
        </tr>
        <tr>
            @if ($type === 'vendor')
                <th>Vendor</th><td>{{ $partyName }}</td>
                <th>D.C./Inv. No</th><td>{{ $arrival->dcno }}</td>
            @elseif ($type === 'stocktr')
                <th>STN No</th><td>{{ $arrival->stnno }}</td>
                <th></th><td></td>
            @else
                <th>Party</th><td>{{ $partyName }}</td>
                <th>Returned From / By</th><td>{{ $arrival->stageret }} / {{ $arrival->retid }}</td>
            @endif
        </tr>
        <tr>
            <th>Mode of Transit</th><td>{{ $arrival->tmode }}</td>
            <th>Stage</th>
            <td><span class="badge">{{ \App\Support\ArrivalStatus::LABELS[$arrival->status] ?? $arrival->status }}</span></td>
        </tr>
        @if ($arrival->tmode === 'Transport')
            <tr><th>Transport</th><td>{{ $arrival->trans_name }} (LR {{ $arrival->trans_lorryrepno }})</td>
                <th>Vehicle / Payment</th><td>{{ $arrival->trans_vehno }} / {{ $arrival->trans_paymode }}</td></tr>
        @elseif ($arrival->tmode === 'Courier')
            <tr><th>Courier</th><td>{{ $arrival->courier_name }}</td><th>Docket No</th><td>{{ $arrival->docket_no }}</td></tr>
        @elseif ($arrival->tmode === 'By Hand')
            <tr><th>Name of Person</th><td colspan="3">{{ $arrival->pname_byhand }}</td></tr>
        @endif
        @if ($arrival->remarks)
            <tr><th>Remarks</th><td colspan="3">{{ $arrival->remarks }}</td></tr>
        @endif
    </table>

    <table class="data">
        <thead>
        <tr>
            <th>#</th><th>Classification</th><th>Item</th><th>UoM</th>
            @if ($type === 'vendor')<th colspan="2">DC</th>@endif
            <th colspan="2">Good</th><th colspan="2">Damage</th><th colspan="2">Excess/Shortage</th>
            <th>SLOC</th>
        </tr>
        <tr>
            <th></th><th></th><th></th><th></th>
            @if ($type === 'vendor')<th>UPS</th><th>QTY</th>@endif
            <th>UPS</th><th>QTY</th><th>UPS</th><th>QTY</th><th>UPS</th><th>QTY</th>
            <th>wh / bin / sub-bin (G/D)</th>
        </tr>
        </thead>
        <tbody>
        @forelse ($arrival->items as $line)
            <tr>
                <td>{{ $loop->iteration }}</td>
                <td>{{ $line->classification?->classification ?? '—' }}</td>
                <td>{{ $line->item?->stores_item ?? '—' }}</td>
                <td>{{ $line->uom }}</td>
                @if ($type === 'vendor')
                    <td>{{ $line->ups_per_dc }}</td>
                    <td>{{ $line->qty_per_dc }}</td>
                @endif
                <td>{{ $line->ups_good }}</td>
                <td>{{ $line->qty_good }}</td>
                <td>{{ $line->ups_damage }}</td>
                <td>{{ $line->qty_damage }}</td>
                <td>{{ $line->exsh_ups }}</td>
                <td>{{ $line->exsh_qty }}</td>
                <td>
                    @forelse ($line->slocs as $sloc)
                        {{ $sloc->whid }} / {{ $sloc->binid }} / {{ $sloc->subbin }}
                        @if ((float) $sloc->qty_good > 0) <span class="badge">G {{ $sloc->ups_good }}/{{ $sloc->qty_good }}</span> @endif
                        @if ((float) $sloc->qty_damage > 0) <span class="badge">D {{ $sloc->ups_damage }}/{{ $sloc->qty_damage }}</span> @endif
                        @unless ($loop->last)<br>@endunless
                    @empty
                        —
                    @endforelse
                </td>
            </tr>
        @empty
            <tr><td colspan="12" class="muted">No lines.</td></tr>
        @endforelse
        </tbody>
    </table>

    <a class="btn secondary" href="{{ route('arrivals.'.$type.'.index') }}" style="margin-top:1rem">Back to list</a>
</div>
@endsection
