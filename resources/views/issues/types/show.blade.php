@extends('layouts.app')

@section('title', $meta['label'].' — detail')

@section('content')
<div class="card">
    <h2>{{ $meta['label'] }} — {{ \App\Support\IssueNumbering::committedId($issue) }}</h2>

    <table class="data" style="margin-bottom:1rem">
        <tr>
            <th>Committed Id</th>
            <td><strong>{{ \App\Support\IssueNumbering::committedId($issue) }}</strong></td>
            <th>Date</th>
            <td>{{ optional($issue->issue_date)->format('d-m-Y') }}</td>
        </tr>
        <tr>
            @if ($type === 'pindent')
                <th>Physical Indent No</th><td>{{ $issue->dcrefno }}</td>
                <th>Raised By</th><td>{{ $issue->strefno }}</td>
            @elseif ($type === 'stocktr')
                <th>Transfer Ref No</th><td>{{ $issue->strefno }}</td>
                <th>Party</th><td>{{ $party?->business_name ?? '—' }}</td>
            @else
                <th>Party DC Ref No</th><td>{{ $issue->dcrefno }}</td>
                <th>Party</th><td>{{ $party?->business_name ?? '—' }}</td>
            @endif
        </tr>
        <tr>
            <th>Mode of Transit</th><td>{{ $issue->tmode }}</td>
            <th>Stage</th><td><span class="badge">{{ \App\Support\EIssueStatus::LABELS[$issue->status] ?? $issue->status }}</span></td>
        </tr>
        @if ($issue->tmode === 'Transport')
            <tr><th>Transport</th><td>{{ $issue->trans_name }} (LR {{ $issue->trans_lorryrepno }})</td>
                <th>Vehicle / Payment</th><td>{{ $issue->trans_vehno }} / {{ $issue->trans_paymode }}</td></tr>
        @elseif ($issue->tmode === 'Courier')
            <tr><th>Courier</th><td>{{ $issue->courier_name }}</td><th>Docket No</th><td>{{ $issue->docket_no }}</td></tr>
        @elseif ($issue->tmode === 'By Hand')
            <tr><th>Name of Person</th><td colspan="3">{{ $issue->pname_byhand }}</td></tr>
        @endif
        @if ($issue->remarks)
            <tr><th>Remarks</th><td colspan="3">{{ $issue->remarks }}</td></tr>
        @endif
    </table>

    <table class="data">
        <thead>
        <tr>
            <th>#</th><th>Classification</th><th>Item</th><th>UoM</th>
            <th>UPS</th><th>Qty</th><th>Distributed at</th>
        </tr>
        </thead>
        <tbody>
        @foreach ($issue->items as $line)
            @foreach ($line->slocs as $s)
                <tr>
                    <td>{{ $loop->parent->iteration }}</td>
                    <td>{{ $line->classification?->classification ?? '—' }}</td>
                    <td>{{ $line->item?->stores_item ?? $line->item_id }}</td>
                    <td>{{ $line->uom }}</td>
                    <td>{{ $s->ups_issue }}</td>
                    <td>{{ $s->qty_issue }}</td>
                    <td>{{ $s->whid }} / {{ $s->binid }} / {{ $s->subbin }}</td>
                </tr>
            @endforeach
        @endforeach
        </tbody>
    </table>

    <a class="btn secondary" href="{{ route('issues.'.$type.'.index') }}" style="margin-top:1rem">Back to list</a>
</div>
@endsection
