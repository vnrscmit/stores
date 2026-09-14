@extends('layouts.app')

@section('title', 'Sub-Bin Card')

@section('content')
<div class="card">
    <h2>Sub-Bin Card</h2>

    <form method="GET" class="filters">
        <div>
            <label>Warehouse</label>
            <select name="warehouse_id" style="width:10rem">
                <option value="">—</option>
                @foreach($warehouses ?? [] as $w)
                    <option value="{{ $w->whid }}" {{ old('warehouse_id', $warehouseId) == $w->whid ? 'selected' : '' }}>{{ $w->perticulars }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label>Bin</label>
            <select name="bin_id" style="width:10rem">
                <option value="">—</option>
                @foreach($bins ?? [] as $b)
                    <option value="{{ $b->binid }}" {{ old('bin_id', $binId) == $b->binid ? 'selected' : '' }}>{{ $b->binname }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label>Sub-bin</label>
            <select name="subbin_id" style="width:10rem">
                <option value="">—</option>
                @foreach($subbins ?? [] as $s)
                    <option value="{{ $s->sid }}" {{ old('subbin_id', $subbinId) == $s->sid ? 'selected' : '' }}>{{ $s->sname }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label>As-of date</label>
            <input type="date" name="as_of" value="{{ $asOf }}">
        </div>
        <button class="btn" type="submit">Print card</button>
    </form>

    @if($subBin)
        <p class="muted" style="margin-top:1rem">
            <strong>Warehouse:</strong> {{ $subBin->warehouse?->perticulars ?? '' }} &nbsp;
            <strong>Bin:</strong> {{ $subBin->bin?->binname ?? '' }} &nbsp;
            <strong>Sub-bin:</strong> {{ $subBin->sname ?? '' }} &nbsp;
            <strong>As-of:</strong> {{ $asOf }}
        </p>
    @endif

    @if(count($rows) > 0)
        <table class="data">
            <thead>
            <tr>
                <th>#</th>
                <th>Classification</th>
                <th>Item</th>
                <th>UoM</th>
                <th style="text-align:right">UPS</th>
                <th style="text-align:right">Qty</th>
            </tr>
            </thead>
            <tbody>
            @foreach($rows as $i => $r)
                <tr>
                    <td style="text-align:center">{{ $i + 1 }}</td>
                    <td>{{ $r['classification'] }}</td>
                    <td>{{ $r['item'] }}</td>
                    <td>{{ $r['uom'] }}</td>
                    <td style="text-align:right">{{ $r['ups'] }}</td>
                    <td style="text-align:right">{{ number_format($r['qty'], 3) }}</td>
                </tr>
                <tr>
                    <td colspan="6" style="padding:0">
                        <table class="data ledger">
                            <thead>
                            <tr>
                                <th>Date</th>
                                <th>Type</th>
                                <th>Sub-type</th>
                                <th>Doc #</th>
                                <th style="text-align:right">In UPS</th>
                                <th style="text-align:right">In Qty</th>
                                <th style="text-align:right">Out UPS</th>
                                <th style="text-align:right">Out Qty</th>
                                <th style="text-align:right">Bal UPS</th>
                                <th style="text-align:right">Bal Qty</th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse($r['ledger'] as $m)
                                <tr>
                                    <td>{{ $m['date'] }}</td>
                                    <td>{{ $m['type'] }}</td>
                                    <td>{{ $m['subtype'] }}</td>
                                    <td>{{ $m['doc'] }}</td>
                                    <td style="text-align:right">{{ $m['in_ups'] }}</td>
                                    <td style="text-align:right">{{ number_format($m['in_qty'], 3) }}</td>
                                    <td style="text-align:right">{{ $m['out_ups'] }}</td>
                                    <td style="text-align:right">{{ number_format($m['out_qty'], 3) }}</td>
                                    <td style="text-align:right">{{ $m['balups'] }}</td>
                                    <td style="text-align:right">{{ number_format($m['balqty'], 3) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="10" style="color:var(--muted)">No movement history in this period.</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @else
        <p style="color:var(--muted)">No stock found for the selected sub-bin and date.</p>
    @endif

    <p class="muted" style="margin-top:1rem;font-size:.8rem">
        Showing items with a positive balance as-of {{ $asOf }}. The movement ledger lists rows from the start of the printed year.
    </p>
</div>
@endsection
