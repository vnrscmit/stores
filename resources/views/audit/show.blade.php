@extends('layouts.app')

@section('title', 'Audit trail — entry')

@section('content')
<div class="card">
    <h2>Audit entry #{{ $entry->id }}</h2>

    <table class="data" style="margin-bottom:1rem">
        <tr>
            <th>When</th>
            <td>{{ $entry->created_at?->format('d-m-Y H:i:s') }}</td>
            <th>User</th>
            <td>{{ $entry->user_login ?? '—' }} (id {{ $entry->user_id ?? '—' }})</td>
        </tr>
        <tr>
            <th>Module</th>
            <td>{{ $entry->module }}</td>
            <th>Action</th>
            <td><strong>{{ $entry->action }}</strong></td>
        </tr>
        <tr>
            <th>Record</th>
            <td>{{ $entry->record_type ? $entry->record_type.' #'.$entry->record_id : '—' }}</td>
            <th>IP</th>
            <td>{{ $entry->ip }}</td>
        </tr>
    </table>

    <div style="display:grid; grid-template-columns:1fr 1fr; gap:1rem">
        <div>
            <h3 style="font-size:.95rem">Before</h3>
            <pre style="background:#f8fafc; border:1px solid #e2e8f0; padding:.75rem; overflow:auto"><code>{{ json_encode($entry->before, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</code></pre>
        </div>
        <div>
            <h3 style="font-size:.95rem">After</h3>
            <pre style="background:#f8fafc; border:1px solid #e2e8f0; padding:.75rem; overflow:auto"><code>{{ json_encode($entry->after, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</code></pre>
        </div>
    </div>

    <div style="margin-top:1rem">
        <a class="btn secondary" href="{{ route('audit.index') }}">Back to trail</a>
    </div>
</div>
@endsection
