@extends('layouts.app')

@section('title', 'Issue '.$issue->transactionId())

@section('content')
<div class="card">
    <h2>Issue {{ $issue->transactionId() }}</h2>

    <table class="data" style="margin-bottom:1rem">
        <tr>
            <th>Committed No</th>
            <td><strong>TIE{{ $issue->iss_code }}/{{ $issue->yearcode }}</strong> (note serial {{ $issue->ncode }})</td>
            <th>Issue Date</th>
            <td>{{ optional($issue->issue_date)->format('d-m-Y') }}</td>
        </tr>
        <tr>
            <th>Indent No</th>
            <td>{{ $issue->dcrefno }}@if ($indent) ({{ \App\Support\IndentNumbering::committedId($indent) }})@endif</td>
            <th>Stage</th>
            <td>{{ \App\Support\EIssueStatus::LABELS[$issue->status] ?? '—' }}</td>
        </tr>
        <tr>
            <th>Issued By (role id)</th>
            <td>{{ $issue->issue_role }}</td>
            <th>Year</th>
            <td>{{ $issue->yearcode }}</td>
        </tr>
    </table>

    @forelse ($issue->items as $item)
        <h3 style="font-size:.95rem; margin:.8rem 0 .4rem">
            {{ $item->classification_id }} / {{ $item->item?->stores_item ?? $item->item_id }}
            — indent qty {{ $item->qty_indent }} {{ $item->uom }}
        </h3>
        <table class="data" style="margin-bottom:1rem">
            <thead>
            <tr>
                <th>SLOC (wh / bin / sub-bin)</th>
                <th>Issue UPS</th>
                <th>Issue Qty</th>
                <th>Balance UPS</th>
                <th>Balance Qty</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($item->slocs as $sloc)
                <tr>
                    <td>{{ $sloc->whid }} / {{ $sloc->binid }} / {{ $sloc->subbin }}</td>
                    <td>{{ $sloc->ups_issue }}</td>
                    <td>{{ $sloc->qty_issue }}</td>
                    <td>{{ $sloc->ups_balance }}</td>
                    <td>{{ $sloc->qty_balance }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @empty
        <p>No lines recorded.</p>
    @endforelse

    <a class="btn secondary" href="{{ route('issues.eindents.index') }}">Back to issues</a>
</div>
@endsection
