@extends('layouts.app')

@section('title', $direction === 'g2d' ? 'Good → Damage — workspace' : 'Damage → Good — workspace')

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
        <tr>
            @if ($direction === 'g2d')
                <th>Party</th>
                <td>{{ \App\Models\Party::find($document->party_id)?->business_name ?? $document->party_id }}</td>
            @else
                <th>Direction</th>
                <td>Damage → Good</td>
            @endif
            <th>Stage</th>
            <td><span class="badge">{{ ($direction === 'g2d' ? (int) $document->gdflg : (int) $document->dgflg) === 1 ? 'Posted' : 'Open' }}</span></td>
        </tr>
        <tr>
            <th>Committed Serial</th>
            <td>
                @if ($direction === 'g2d')
                    {{ $document->gcode ? \App\Support\DocumentNumber::pretty('gtod', (int) $document->gcode, (string) $document->yearcode) : '—' }}
                @else
                    {{ $document->dcode ? \App\Support\DocumentNumber::pretty('dtog', (int) $document->dcode, (string) $document->yearcode) : '—' }}
                @endif
            </td>
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
            <tr><td colspan="6" class="muted">No rows yet.</td></tr>
        @endforelse
        </tbody>
    </table>

    @if (($direction === 'g2d' ? (int) $document->gdflg : (int) $document->dgflg) !== 1)
        <div style="margin-top:1rem; display:flex; gap:.5rem; align-items:center">
            <form method="POST" action="{{ $direction === 'g2d' ? route('gatemovements.post', $document) : route('gatemovements.post-d2g', $document) }}">
                @csrf
                <button class="btn" type="submit">Post conversion</button>
            </form>
            <a class="btn secondary" href="{{ $direction === 'g2d' ? route('gatemovements.create') : route('gatemovements.create-d2g') }}">Add / replace rows</a>
            <form method="POST" action="{{ $direction === 'g2d' ? route('gatemovements.header.update', $document) : route('gatemovements.header.update-d2g', $document) }}" style="display:flex; gap:.5rem; align-items:end">
                @csrf
                @method('PUT')
                <div>
                    <label>Date</label>
                    <input type="date" name="tdate" value="{{ $document->date }}">
                </div>
                <div>
                    <label>Remarks</label>
                    <input type="text" name="remarks" value="{{ $document->remarks }}" maxlength="1000">
                </div>
                @if ($direction === 'g2d')
                    <div>
                        <label>Party</label>
                        <select name="party_id">
                            @foreach ($parties as $party)
                                <option value="{{ $party->p_id }}" @selected((int) $party->p_id === (int) $document->party_id)>{{ $party->business_name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
                <button class="btn secondary" type="submit">Update header</button>
            </form>
        </div>
    @endif
</div>
@endsection
