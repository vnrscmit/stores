@extends('layouts.app')

@section('title', 'Material Discard — '.\App\Support\DocumentNumber::pretty('discard', (int) $discard->dd_code, (string) $discard->yearcode))

@section('content')
<div class="card">
    <h2>
        Material Discard —
        {{ \App\Support\DocumentNumber::pretty('discard', (int) $discard->dd_code, (string) $discard->yearcode) }}
    </h2>

    <table class="data" style="margin-bottom:1rem">
        <tr>
            <th>Transaction Id</th>
            <td><strong>TMD{{ $discard->tcode }}/{{ $discard->yearcode }}/{{ $discard->ddrole }}</strong></td>
            <th>Discard Date</th>
            <td>{{ $discard->tdate ? \Illuminate\Support\Carbon::parse($discard->tdate)->format('d-m-Y') : '—' }}</td>
        </tr>
        <tr>
            <th>Discard Inst. Ref. No.</th>
            <td>{{ $discard->drno }}</td>
            <th>Party</th>
            <td>{{ $discard->party_name }}</td>
        </tr>
        <tr>
            <th>Address</th>
            <td colspan="3">
                {{ collect([$discard->address, $discard->address1, $discard->city, $discard->pin, $discard->state])->filter()->implode(' ') }}
                @if ($discard->phoneno)&nbsp;Ph: {{ $discard->phoneno }}@endif
            </td>
        </tr>
        <tr>
            <th>Mode of Transit</th>
            <td>{{ $discard->tmode ?: 'Not Applicable' }}</td>
            <th>Return status</th>
            <td>{{ $discard->rettyp }}</td>
        </tr>
        @if ($discard->tmode === 'Transport')
            <tr>
                <th>Transport Name</th><td>{{ $discard->tname }}</td>
                <th>Lorry Receipt No.</th><td>{{ $discard->lrno }}</td>
            </tr>
            <tr>
                <th>Vehicle No.</th><td>{{ $discard->vno }}</td>
                <th>Payment Mode</th><td>{{ $discard->pmode === 'ToPay' ? 'To Pay' : $discard->pmode }} (Transport)</td>
            </tr>
        @elseif ($discard->tmode === 'Courier')
            <tr>
                <th>Courier Name</th><td>{{ $discard->cname }}</td>
                <th>Docket No.</th><td>{{ $discard->dcno }}</td>
            </tr>
        @elseif ($discard->tmode !== '' && $discard->tmode !== null)
            <tr>
                <th>Name of Person</th><td colspan="3">{{ $discard->pname }}</td>
            </tr>
        @endif
        @if ($discard->remarks)
            <tr><th>Remarks</th><td colspan="3">{{ $discard->remarks }}</td></tr>
        @endif
    </table>

    <table class="data">
        <thead>
        <tr>
            <th>#</th>
            <th>Item</th>
            <th>UoM</th>
            <th>Discard UPS</th>
            <th>Discard Qty</th>
            <th>Balance UPS</th>
            <th>Balance Qty</th>
        </tr>
        </thead>
        <tbody>
        @forelse ($lines as $line)
            <tr>
                <td>{{ $loop->iteration }}</td>
                <td>{{ $line['item_name'] }}</td>
                <td>{{ $line['uom'] }}</td>
                <td>{{ $line['ups'] }}</td>
                <td>{{ $line['qty'] }}</td>
                <td>{{ collect($line['rows'])->pluck('ups_balance')->implode(' / ') }}</td>
                <td>{{ collect($line['rows'])->pluck('qty_balance')->implode(' / ') }}</td>
            </tr>
            @foreach ($line['rows'] as $row)
                <tr>
                    <td></td>
                    <td colspan="2" style="padding-left:2rem">
                        SLOC {{ $row['whid'] }} / {{ $row['binid'] }} / {{ $row['subbinid'] }}
                    </td>
                    <td>{{ $row['ups_discard'] }}</td>
                    <td>{{ $row['qty_discard'] }}</td>
                    <td>{{ $row['ups_balance'] }}</td>
                    <td>{{ $row['qty_balance'] }}</td>
                </tr>
            @endforeach
        @empty
            <tr><td colspan="7" class="muted">No lines.</td></tr>
        @endforelse
        </tbody>
    </table>

    <a class="btn secondary" href="{{ route('discards.index') }}" style="margin-top:1rem">Back to list</a>
</div>
@endsection
