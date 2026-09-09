@extends('layouts.app')

@section('title', 'Reports')

@section('content')
<div class="card">
    <h2>Reports catalogue</h2>
    <table class="data">
        <thead><tr><th>Report</th><th>Status</th><th></th></tr></thead>
        <tbody>
        @foreach ($reports as $r)
            <tr>
                <td>{{ $r['label'] }}</td>
                <td>
                    @if ($r['live'])
                        <span class="badge">available</span>
                    @else
                        <span style="color:var(--muted);font-size:.8rem">phase 11</span>
                    @endif
                </td>
                <td>
                    @if ($r['live'])
                        <a href="{{ route('viewer.reports.'.$r['code']) }}">Open</a>
                    @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
@endsection
