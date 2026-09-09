@extends('layouts.app')

@section('title', $title)

@section('content')
<h1 style="font-size:1.3rem;margin:.2rem 0 1rem">{{ $title }} dashboard</h1>
<div class="grid">
    @foreach ($modules as $m)
        <a class="module" href="{{ $m['url'] }}">
            <strong>{{ $m['label'] }}</strong>
            <div class="desc">{{ $m['desc'] }}</div>
        </a>
    @endforeach
</div>
@endsection
