@extends('layouts.app')

@section('title', 'Database backup')

@section('content')
<div class="card">
    <h2>Database backup</h2>

    <p class="muted">
        Legacy "Backup" (utility/backup.php): a full SQL dump of every table —
        schema and data — streamed as one <code>.sql</code> file. Restore it into an
        EMPTY database (the dump carries no DROP statements, verbatim legacy
        semantics). The business-tables variant mirrors the legacy backup1.php
        data-only dump of the 51 business tables.
    </p>

    <table class="data" style="margin-bottom:1rem">
        <tr>
            <th>Database</th>
            <td>{{ DB::connection()->getDatabaseName() }}</td>
            <th>Tables</th>
            <td>{{ $tables }}</td>
        </tr>
        <tr>
            <th>Filename</th>
            <td colspan="3">{{ $filename }}</td>
        </tr>
    </table>

    <div style="display:flex; gap:.5rem; align-items:center">
        <a class="btn" href="{{ route('admin.backup.download') }}">Download full backup</a>
        <a class="btn secondary" href="{{ route('admin.backup.download-business') }}">Download business tables only</a>
    </div>
</div>
@endsection
