@extends('layouts.app')

@section('title', 'Issues against e-Indents')

@section('content')
<div class="card">
    <h2>Issues against e-Indents</h2>

    <form method="GET" class="filters">
        <div>
            <label for="stage">Stage</label>
            <select id="stage" name="stage">
                <option value="">All</option>
                <option value="open" @selected(request('stage') === 'open')>Open (awaiting post)</option>
                <option value="posted" @selected(request('stage') === 'posted')>Posted</option>
            </select>
        </div>
        <button class="btn" type="submit">Filter</button>
        @if (request('stage'))
            <a class="btn secondary" href="{{ route('issues.eindents.index') }}">Reset</a>
        @endif
    </form>

    <table class="data">
        <thead>
        <tr>
            <th>#</th>
            <th>Transaction Id</th>
            <th>Issue Date</th>
            <th>Indent No</th>
            <th>Lines</th>
            <th>Stage</th>
            <th></th>
        </tr>
        </thead>
        <tbody>
        @forelse ($issues as $issue)
            <tr>
                <td>{{ $issues->firstItem() + $loop->index }}</td>
                <td><a href="{{ route('issues.eindents.show', $issue) }}">{{ $issue->transactionId() }}</a></td>
                <td>{{ optional($issue->issue_date)->format('d-m-Y') }}</td>
                <td>{{ $issue->dcrefno }}</td>
                <td>{{ $issue->items->count() }}</td>
                <td>{{ \App\Support\EIssueStatus::LABELS[$issue->status] ?? '—' }}</td>
                <td>
                    @if (! $issue->isPosted())
                        {{-- Resume via the indent workspace --}}
                        <a class="btn secondary" href="{{ route('issues.eindents.workspace', $issue->dcrefno) }}">Resume</a>
                    @else
                        <a class="btn secondary" href="{{ route('issues.eindents.show', $issue) }}">View</a>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="7">No issues recorded for this financial year yet.</td></tr>
        @endforelse
        </tbody>
    </table>

    {{ $issues->links() }}
</div>
@endsection
