@extends('layouts.app')

@section('title', 'Discard Report')

@section('content')
<div class="card">
    <h2>Discard Report</h2>
    <form method="GET" class="filters">
        <div><label>From</label><input type="date" name="from" value="{{ $from }}"></div>
        <div><label>To</label><input type="date" name="to" value="{{ $to }}"></div>
        <div><label>Classification ID</label><input type="number" name="classification_id" value="{{ $classificationId }}" style="width:8rem"></div>
        <div><label>Item ID</label><input type="number" name="item_id" value="{{ $itemId }}" style="width:8rem"></div>
        <button class="btn" type="submit">Apply</button>
        <a class="btn secondary" href="{{ route('viewer.reports.discard.export', request()->query()) }}">Export XLSX</a>
    </form>

    <p class="muted" style="margin:.75rem 0 .25rem">
        Period: {{ $from }} to {{ $to }} &middot; Classification: {{ $classificationLabel }}
    </p>

    <table class="data">
        <thead>
        <tr>
            <th>Date</th>
            <th>Perticulars</th>
            <th>Classification</th>
            <th>Item</th>
            <th style="text-align:right">UPS</th>
            <th style="text-align:right">Qty</th>
        </tr>
        </thead>
        <tbody>
        @forelse ($rows as $r)
            <tr>
                <td>{{ $r['date'] }}</td>
                <td>{{ $r['particulars'] }}</td>
                <td>{{ $r['classification'] }}</td>
                <td>
                    {{ $r['item'] }}
                    @if ($r['inactive'])
                        <span style="color:#c0392b">(In-Active)</span>
                    @endif
                </td>
                <td style="text-align:right">{{ $r['ups'] }}</td>
                <td style="text-align:right">{{ number_format($r['qty'], 3) }}</td>
            </tr>
        @empty
            <tr><td colspan="6" style="color:var(--muted)">No discard movements in the selected period.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
