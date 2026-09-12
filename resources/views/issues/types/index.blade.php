@extends('layouts.app')

@section('title', $meta['label'])

@section('content')
<div class="card">
    <div style="display:flex; justify-content:space-between; align-items:center; gap:1rem; flex-wrap:wrap">
        <h2>{{ $meta['label'] }}</h2>
        <a class="btn" href="{{ route('issues.'.$type.'.create') }}">New {{ $meta['label'] }}</a>
    </div>

    <form method="GET" class="filters" style="display:flex; gap:.75rem; align-items:end; margin:1rem 0; flex-wrap:wrap">
        <div>
            <label class="muted">Search</label>
            <input type="text" name="q" value="{{ request('q') }}" placeholder="Transaction id or party" style="min-width:16rem">
        </div>
        <div>
            <label class="muted">Stage</label>
            <select name="stage">
                <option value="">All</option>
                <option value="open" @selected(request('stage') === 'open')>Open</option>
                <option value="posted" @selected(request('stage') === 'posted')>Posted</option>
            </select>
        </div>
        <button class="btn secondary" type="submit">Filter</button>
    </form>

    <table class="data">
        <thead>
        <tr>
            <th>Transaction Id</th>
            <th>Date</th>
            <th>Reference</th>
            <th>Stage</th>
            <th>Committed</th>
            <th></th>
        </tr>
        </thead>
        <tbody>
        @forelse ($issues as $issue)
            <tr>
                <td>{{ $issue->transactionId() }}</td>
                <td>{{ optional($issue->issue_date)->format('d-m-Y') }}</td>
                <td>{{ $type === \App\Support\IssueTypes::STOCK_TRANSFER ? ($issue->strefno ?: '—') : ($issue->dcrefno ?: '—') }}</td>
                <td><span class="badge">{{ \App\Support\EIssueStatus::LABELS[$issue->status] ?? $issue->status }}</span></td>
                <td>{{ $issue->iss_code ? \App\Support\IssueNumbering::committedId($issue) : '—' }}</td>
                <td>
                    @if ($issue->issuetrflag == 0)
                        <a class="btn secondary" href="{{ route('issues.'.$type.'.workspace', $issue) }}">Open workspace</a>
                    @else
                        <a class="btn secondary" href="{{ route('issues.'.$type.'.show', $issue) }}">View</a>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="6">No transactions yet.</td></tr>
        @endforelse
        </tbody>
    </table>

    {{ $issues->links() }}
</div>
@endsection
