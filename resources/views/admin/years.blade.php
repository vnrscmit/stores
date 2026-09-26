@extends('layouts.app')

@section('title', 'Year Setting')

@section('content')
<div class="card">
    <h2>Year Setting</h2>

    <p class="muted">
        Active year: <strong>{{ $active->year_name }}</strong> ({{ $active->ycode }}).
        Legacy current_year.php activates a year; closeyear.php closes the active
        year (status 'c') and opens the next one (its yearsid + 1).
    </p>

    <table class="data">
        <thead>
        <tr>
            <th>Year</th>
            <th>Code</th>
            <th>Flag</th>
            <th>Status</th>
            <th></th>
        </tr>
        </thead>
        <tbody>
        @foreach ($years as $year)
            <tr @class(['muted' => $year->years_status === 'c'])>
                <td>
                    <strong>{{ $year->year_name }}</strong>
                    @if ($year->yearsid === $active->yearsid)
                        <span class="badge">active</span>
                    @endif
                </td>
                <td>{{ $year->ycode }}</td>
                <td>{{ $year->years_flg }}</td>
                <td>{{ $year->years_status }}</td>
                <td>
                    @if ($year->yearsid !== $active->yearsid && $year->years_status !== 'c')
                        <form method="POST" action="{{ route('admin.years.activate') }}" style="display:inline">
                            @csrf
                            <input type="hidden" name="yearsid" value="{{ $year->yearsid }}">
                            <button class="btn secondary" type="submit">Activate</button>
                        </form>
                    @elseif ($year->years_status === 'c')
                        closed
                    @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <div style="margin-top:1rem; display:flex; gap:.75rem; align-items:center">
        @if ($successor !== null)
            <form method="POST" action="{{ route('admin.years.close') }}">
                @csrf
                <button class="btn" type="submit"
                        onclick="return confirm('Close {{ $active->year_name }} and activate {{ $successor->year_name }}?')">
                    Close year &amp; activate {{ $successor->year_name }}
                </button>
            </form>
            <span class="muted">Successor found by yearsid + 1 (legacy closeyear.php).</span>
        @else
            <span class="muted">No successor year exists after {{ $active->year_name }} — closing is unavailable.</span>
        @endif
    </div>
</div>
@endsection
