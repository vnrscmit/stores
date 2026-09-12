@extends('layouts.app')

@section('title', 'Captive Consumption — detail')

@section('content')
<div class="card">
    <h2>Captive Consumption — CC{{ $captive->cc_code }}</h2>

    <table class="data" style="margin-bottom:1rem">
        <tr>
            <th>Committed</th>
            <td><strong>CC{{ $captive->cc_code }}</strong></td>
            <th>Date</th>
            <td>{{ optional($captive->tdate)->format('d-m-Y') }}</td>
        </tr>
        <tr>
            <th>Party</th>
            <td>{{ $party?->business_name ?? $captive->party_name }}</td>
            <th>Return Type</th>
            <td>{{ $captive->rettyp }}</td>
        </tr>
        <tr>
            <th>Mode of Transit</th><td>{{ $captive->tmode }}</td>
            <th>Stage</th><td><span class="badge">Posted (stock updated)</span></td>
        </tr>
        @if ($captive->tmode === 'Transport')
            <tr><th>Transport</th><td>{{ $captive->tname }} (LR {{ $captive->lrno }})</td>
                <th>Vehicle / Payment</th><td>{{ $captive->vno }} / {{ $captive->pmode }}</td></tr>
        @elseif ($captive->tmode === 'Courier')
            <tr><th>Courier</th><td>{{ $captive->cname }}</td><th>Docket No</th><td>{{ $captive->docketno }}</td></tr>
        @elseif ($captive->tmode === 'By Hand')
            <tr><th>Name of Person</th><td colspan="3">{{ $captive->pname }}</td></tr>
        @endif
        @if ($captive->remarks)
            <tr><th>Remarks</th><td colspan="3">{{ $captive->remarks }}</td></tr>
        @endif
    </table>

    <table class="data">
        <thead>
        <tr>
            <th>#</th><th>Classification</th><th>Item</th><th>UoM</th><th>Condition</th>
            <th>UPS</th><th>Qty</th><th>Distributed at</th>
        </tr>
        </thead>
        <tbody>
        @foreach ($captive->items as $line)
            @forelse ($line->slocs as $s)
                <tr>
                    <td>{{ $loop->parent->iteration }}</td>
                    <td>{{ $line->classification?->classification ?? '—' }}</td>
                    <td>{{ $line->item?->stores_item ?? $line->items_id }}</td>
                    <td>{{ $line->uom }}</td>
                    <td>{{ $line->type }}</td>
                    <td>{{ $s->ups_issue }}</td>
                    <td>{{ $s->qty_issue }}</td>
                    <td>{{ $s->whid }} / {{ $s->binid }} / {{ $s->subbin }}</td>
                </tr>
            @empty
                <tr><td colspan="8">No distribution rows.</td></tr>
            @endforelse
        @endforeach
        </tbody>
    </table>

    <a class="btn secondary" href="{{ route('issues.cc.index') }}" style="margin-top:1rem">Back to list</a>
</div>
@endsection
