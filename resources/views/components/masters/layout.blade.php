@props(['title', 'createRoute' => null, 'createLabel' => 'Add New', 'exportRoute' => null])

@extends('layouts.app')

@section('title', $title)

@section('content')
<div class="card">
    <div style="display:flex; align-items:center; gap:1rem; margin-bottom:.75rem">
        <h2 style="margin:0">{{ $title }}</h2>
        @if ($createRoute)
            <a class="btn" href="{{ route($createRoute) }}">{{ $createLabel }}</a>
        @endif
    </div>

    <form method="GET" class="filters">
        @isset($filters)
            {{ $filters }}
        @endisset
        <button class="btn" type="submit">Search</button>
        @if (request()->anyFilled(['q', 'whid', 'binid', 'classification_id', 'actstatus']))
            <a class="btn secondary" href="{{ url()->current() }}">Reset</a>
        @endif
        @if ($exportRoute)
            <a class="btn secondary" href="{{ route($exportRoute, request()->query()) }}">Export XLSX</a>
        @endif
    </form>

    {{ $slot }}
</div>
@endsection
