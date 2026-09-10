@extends('layouts.app')

@section('title', 'My e-Indents')

@section('content')
<div class="card">
    <div style="display:flex; align-items:center; gap:1rem; margin-bottom:.75rem">
        <h2 style="margin:0">My e-Indents</h2>
        @if ($canRaiseMore)
            <a class="btn" href="{{ route('eindents.raise') }}">Raise e-Indent</a>
        @else
            <span class="muted">Limit reached — you have 3 open indents (pending/approved).</span>
        @endif
    </div>

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
            <a class="btn secondary" href="{{ route('eindents.index') }}">Reset</a>
        @endif
    </form>

    <table>
        <thead>
        <tr>
            <th>#</th>
            <th>Transaction Id</th>
            <th>Indent No</th>
            <th>Date</th>
            <th>Items</th>
            <th>Stage</th>
        </tr>
        </thead>
        <tbody>
        @forelse ($indents as $indent)
            <tr>
                <td>{{ $indents->firstItem() + $loop->index }}</td>
                <td><a href="{{ route('eindents.show', $indent) }}">{{ \App\Support\IndentNumbering::transactionId($indent) }}</a></td>
                <td>{{ $indent->tflg ? \App\Support\IndentNumbering::committedId($indent) : 'T'.$indent->code1 }}</td>
                <td>{{ optional($indent->tdate)->format('d-m-Y') }}</td>
                <td>{{ $indent->items_count }}</td>
                <td>{{ \App\Support\EIndentStatus::LABELS[$indent->status] ?? '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="muted">No indents yet. Use “Raise e-Indent” to start one.</td></tr>
        @endforelse
        </tbody>
    </table>

    {{ $indents->links() }}
</div>
@endsection
