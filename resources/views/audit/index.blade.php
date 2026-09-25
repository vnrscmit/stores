@extends('layouts.app')

@section('title', 'Audit trail')

@section('content')
<div class="card">
    <h2>Audit trail</h2>

    <p class="muted">
        Every master change, approval and transaction posting is logged here
        (module, action, user, snapshots, IP) — newest first. The legacy
        system's "audit trail" was a developer debug page; this screen reads
        the trail the port has been writing since its first transaction slice.
    </p>

    <form method="GET" action="{{ route('audit.index') }}"
          style="display:grid; grid-template-columns:repeat(auto-fit,minmax(9rem,1fr)); gap:.75rem; align-items:end; margin:.75rem 0 1rem">
        <div>
            <label>Module</label>
            <select name="module">
                <option value="">All modules</option>
                @foreach ($modules as $m)
                    <option value="{{ $m }}" @selected($filters['module'] === $m)>{{ $m }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label>Action</label>
            <input type="text" name="action" value="{{ $filters['action'] }}" placeholder="e.g. post">
        </div>
        <div>
            <label>User login</label>
            <input type="text" name="user" value="{{ $filters['user'] }}" placeholder="contains…">
        </div>
        <div>
            <label>From</label>
            <input type="date" name="from" value="{{ $filters['from'] }}">
        </div>
        <div>
            <label>To</label>
            <input type="date" name="to" value="{{ $filters['to'] }}">
        </div>
        <div>
            <button class="btn" type="submit">Filter</button>
            <a class="btn secondary" href="{{ route('audit.index') }}">Reset</a>
        </div>
    </form>

    @if ($byAction->isNotEmpty())
        <p class="muted" style="margin:.25rem 0 .75rem">
            @foreach ($byAction as $action => $count)
                <span class="badge">{{ $action }}: {{ $count }}</span>
            @endforeach
        </p>
    @endif

    <table class="data">
        <thead>
        <tr>
            <th>#</th>
            <th>When</th>
            <th>Module</th>
            <th>Action</th>
            <th>User</th>
            <th>Record</th>
            <th>IP</th>
            <th></th>
        </tr>
        </thead>
        <tbody>
        @forelse ($entries as $entry)
            <tr>
                <td>{{ $entry->id }}</td>
                <td>{{ $entry->created_at?->format('d-m-Y H:i:s') }}</td>
                <td>{{ $entry->module }}</td>
                <td><strong>{{ $entry->action }}</strong></td>
                <td>{{ $entry->user_login ?? '—' }}</td>
                <td>
                    {{ $entry->record_type ? $entry->record_type.' #'.$entry->record_id : '—' }}
                </td>
                <td>{{ $entry->ip }}</td>
                <td><a class="btn secondary" href="{{ route('audit.show', $entry) }}">View</a></td>
            </tr>
        @empty
            <tr><td colspan="8" class="muted">No audit entries match the filters.</td></tr>
        @endforelse
        </tbody>
    </table>

    {{ $entries->links() }}
</div>
@endsection
