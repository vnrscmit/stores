@extends('layouts.app')

@section('title', 'e-Indent Approvals')

@section('content')
<div class="card">
    <h2>e-Indent Approvals</h2>

    <form method="GET" class="filters">
        <input type="text" name="q" value="{{ $q }}" placeholder="Code or remarks">
        <select name="status">
            <option value="">All statuses</option>
            @foreach (\App\Support\EIndentStatus::FILTERS as $s)
                <option value="{{ $s }}" @selected($status === $s)>{{ \App\Support\EIndentStatus::LABELS[$s] }}</option>
            @endforeach
        </select>
        <button class="btn" type="submit">Search</button>
        @if ($q !== '' || $status !== '')
            <a class="btn secondary" href="{{ route('eindents.approvals') }}">Reset</a>
        @endif
    </form>

    <table>
        <thead>
        <tr>
            <th>#</th>
            <th>Transaction Id</th>
            <th>Indent No</th>
            <th>Date</th>
            <th>Raised by</th>
            <th>Items</th>
            <th>Stage</th>
            <th>Decision</th>
        </tr>
        </thead>
        <tbody>
        @forelse ($indents as $indent)
            <tr>
                <td>{{ $indents->firstItem() + $loop->index }}</td>
                <td><a href="{{ route('eindents.show', $indent) }}">{{ \App\Support\IndentNumbering::transactionId($indent) }}</a></td>
                <td>{{ $indent->tflg ? \App\Support\IndentNumbering::committedId($indent) : 'T'.$indent->code1 }}</td>
                <td>{{ optional($indent->tdate)->format('d-m-Y') }}</td>
                <td>{{ $indent->raiser->login ?? '—' }}</td>
                <td>{{ $indent->items_count }}</td>
                <td>{{ \App\Support\EIndentStatus::LABELS[$indent->status] ?? '—' }}</td>
                <td>
                    @if ($indent->status === \App\Support\EIndentStatus::PENDING)
                        <form method="POST" action="{{ route('eindents.approve', $indent) }}" style="display:inline">
                            @csrf
                            <button class="btn" type="submit">Approve</button>
                        </form>
                        <form method="POST" action="{{ route('eindents.reject', $indent) }}" style="display:inline">
                            @csrf
                            <button class="btn danger" type="submit">Reject</button>
                        </form>
                    @else
                        —
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="8" class="muted">Nothing waiting for approval.</td></tr>
        @endforelse
        </tbody>
    </table>

    {{ $indents->links() }}
</div>
@endsection
