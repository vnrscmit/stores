@extends('layouts.app')

@section('title', 'Issue — Pending e-Indents')

@section('content')
<div class="card">
    <h2>Issue against e-Indents — pending queue</h2>

    <form method="GET" class="filters">
        <div>
            <label for="q">Indent no / raised by</label>
            <input type="text" id="q" name="q" value="{{ $search }}">
        </div>
        <button class="btn" type="submit">Search</button>
        @if ($search !== '')
            <a class="btn secondary" href="{{ route('issues.eindents.pending') }}">Reset</a>
        @endif
    </form>

    <table class="data">
        <thead>
        <tr>
            <th>#</th>
            <th>Indent No</th>
            <th>Date</th>
            <th>Raised By</th>
            <th>Lines</th>
            <th></th>
        </tr>
        </thead>
        <tbody>
        @forelse ($indents as $indent)
            <tr>
                <td>{{ $indents->firstItem() + $loop->index }}</td>
                <td><strong>{{ \App\Support\IndentNumbering::committedId($indent) }}</strong></td>
                <td>{{ optional($indent->tdate)->format('d-m-Y') }}</td>
                <td>{{ $indent->raiser?->login }}</td>
                <td>{{ $indent->items_count }}</td>
                <td><a class="btn" href="{{ route('issues.eindents.workspace', $indent) }}">Open issue workspace</a></td>
            </tr>
        @empty
            <tr><td colspan="6">No approved indents are waiting for issue.</td></tr>
        @endforelse
        </tbody>
    </table>

    {{ $indents->links() }}
</div>
@endsection
