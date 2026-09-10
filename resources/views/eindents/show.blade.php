@extends('layouts.app')

@section('title', 'e-Indent Detail')

@section('content')
<div class="card">
    <h2>e-Indent {{ \App\Support\IndentNumbering::transactionId($indent) }}</h2>

    <div class="filters" style="display:flex; gap:2rem; margin:.5rem 0 1rem; flex-wrap:wrap">
        <div><span class="muted">Indent No</span><br>
            <strong>{{ $indent->tflg ? \App\Support\IndentNumbering::committedId($indent) : 'T'.$indent->code1 }}</strong></div>
        <div><span class="muted">Indent Date</span><br>
            <strong>{{ optional($indent->tdate)->format('d-m-Y') }}</strong></div>
        <div><span class="muted">Raised by</span><br>
            <strong>{{ $indent->raiser->login ?? '—' }}</strong></div>
        <div><span class="muted">Stage</span><br>
            <strong>{{ \App\Support\EIndentStatus::LABELS[$indent->status] ?? '—' }}</strong></div>
        @if ($indent->flg)
            <div><span class="muted">Issuance</span><br><strong>Closed (issued)</strong></div>
        @endif
    </div>

    <table>
        <thead>
        <tr><th>#</th><th>Classification</th><th>Item</th><th>UoM</th><th>UPS</th><th>Quantity</th></tr>
        </thead>
        <tbody>
        @foreach ($indent->items as $i => $row)
            <tr>
                <td>{{ $i + 1 }}</td>
                <td>{{ $row->classification->classification ?? '—' }}</td>
                <td>{{ $row->item->stores_item ?? '—' }}</td>
                <td>{{ $row->uom }}</td>
                <td>{{ $row->ups }}</td>
                <td>{{ $row->qty }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <p style="margin-top:.75rem"><span class="muted">Remarks:</span> {{ $indent->remarks ?? '—' }}</p>

    <div style="margin-top:1rem; display:flex; gap:.75rem">
        @if ($isOwner && $indent->tflg == 0)
            <a class="btn" href="{{ route('eindents.workspace', $indent) }}">Continue Editing</a>
            <form method="POST" action="{{ route('eindents.submit', $indent) }}"
                  onsubmit="return confirm('Have You completed the Transaction?\nDo You wish to Final Submit it?')">
                @csrf
                <button class="btn" type="submit">Final Submit for Approval</button>
            </form>
        @endif

        @if ($isOwner && $indent->status === \App\Support\EIndentStatus::REJECTED)
            <form method="POST" action="{{ route('eindents.reopen', $indent) }}">
                @csrf
                <button class="btn" type="submit">Reopen as Draft</button>
            </form>
        @endif

        @if (auth()->user()->can('manage-masters') && $indent->status === \App\Support\EIndentStatus::PENDING)
            <form method="POST" action="{{ route('eindents.approve', $indent) }}">
                @csrf
                <button class="btn" type="submit">Approve</button>
            </form>
            <form method="POST" action="{{ route('eindents.reject', $indent) }}">
                @csrf
                <button class="btn danger" type="submit">Reject</button>
            </form>
        @endif

        <a class="btn secondary" href="{{ url()->previous() }}">Back</a>
    </div>
</div>
@endsection
