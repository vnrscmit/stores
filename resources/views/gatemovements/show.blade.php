@extends('layouts.app')

@section('title', $direction === 'g2d' ? 'Good → Damage — document' : 'Damage → Good — document')

@section('content')
<div class="card">
    <h2>{{ $direction === 'g2d' ? 'Good → Damage' : 'Damage → Good' }} —
        {{ $direction === 'g2d' ? 'TGD' : 'TDG' }}{{ $document->code }}/{{ $document->yearcode }}</h2>

    <table class="data" style="margin-bottom:1rem">
        <tr>
            <th>Document</th>
            <td><strong>{{ $direction === 'g2d' ? 'TGD' : 'TDG' }}{{ $document->code }}/{{ $document->yearcode }}</strong></td>
            <th>Date</th>
            <td>{{ $document->date ? \Illuminate\Support\Carbon::parse($document->date)->format('d-m-Y') : '—' }}</td>
        </tr>
        @if ($direction === 'g2d')
            <tr>
                <th>Party</th>
                <td>{{ \App\Models\Party::find($document->party_id)?->business_name ?? $document->party_id }}</td>
                <th>Committed Serial</th>
                <td>{{ $document->gcode ? \App\Support\DocumentNumber::pretty('gtod', (int) $document->gcode, (string) $document->yearcode) : '—' }}</td>
            </tr>
        @else
            <tr>
                <th>Direction</th>
                <td>Damage → Good</td>
                <th>Committed Serial</th>
                <td>{{ $document->dcode ? \App\Support\DocumentNumber::pretty('dtog', (int) $document->dcode, (string) $document->yearcode) : '—' }}</td>
            </tr>
        @endif
        <tr>
            <th>Stage</th>
            <td><span class="badge">{{ ($direction === 'g2d' ? (int) $document->gdflg : (int) $document->dgflg) === 1 ? 'Posted' : 'Open' }}</span></td>
            <th>Item</th>
            <td>{{ $document->items_id }} ({{ $document->uom }})</td>
        </tr>
        @if ($document->remarks)
            <tr><th>Remarks</th><td colspan="3">{{ $document->remarks }}</td></tr>
        @endif
    </table>

    <h3 style="font-size:.95rem">Conversion rows</h3>
    <table class="data">
        <thead>
        <tr>
            <th>Source SLOC</th>
            <th>Source Balance</th>
            <th>Destination SLOC</th>
            <th>Convert UPS</th>
            <th>Convert Qty</th>
            <th>Dest. Post Balance</th>
        </tr>
        </thead>
        <tbody>
        @forelse ($lines as $line)
            <tr>
                <td>
                    @if ($line['src_subbinid'] === null)
                        <span class="muted">row gone</span>
                    @else
                        {{ $line['src_whid'] }} / {{ $line['src_binid'] }} / {{ $line['src_subbinid'] }}
                    @endif
                </td>
                <td>
                    @if ($line['current_balance'] !== null)
                        {{ $line['current_balance']['ups'] }} / {{ $line['current_balance']['qty'] }}
                    @endif
                </td>
                <td>{{ $line['whid'] }} / {{ $line['binid'] }} / {{ $line['subbinid'] }}</td>
                <td>{{ $line['ups'] }}</td>
                <td>{{ $line['qty'] }}</td>
                <td>{{ $line['balups'] }} / {{ $line['balqty'] }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="muted">No rows.</td></tr>
        @endforelse
        </tbody>
    </table>

    <div style="margin-top:1rem">
        <a class="btn secondary" href="{{ $direction === 'g2d' ? route('gatemovements.index') : route('gatemovements.index-d2g') }}">Back to queue</a>
    </div>
</div>
@endsection
