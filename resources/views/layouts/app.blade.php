<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Stores') — {{ config('app.name') }}</title>
    <style>
        :root { --brand:#2563eb; --ink:#1e293b; --muted:#64748b; --line:#e2e8f0; --bg:#f8fafc; }
        * { box-sizing:border-box; }
        body { margin:0; font-family:system-ui,Segoe UI,Arial,sans-serif; color:var(--ink); background:var(--bg); }
        header { background:#fff; border-bottom:1px solid var(--line); padding:.65rem 1.25rem; display:flex; align-items:center; gap:1rem; }
        header .brand { font-weight:700; color:var(--brand); text-decoration:none; font-size:1.05rem; }
        header nav { margin-left:auto; display:flex; gap:.75rem; align-items:center; }
        header nav a { color:var(--ink); text-decoration:none; font-size:.92rem; }
        header nav a:hover { color:var(--brand); }
        .badge { background:var(--brand); color:#fff; border-radius:999px; padding:.15rem .6rem; font-size:.75rem; }
        main { max-width:1200px; margin:1.5rem auto; padding:0 1rem; }
        .card { background:#fff; border:1px solid var(--line); border-radius:.6rem; padding:1.25rem; margin-bottom:1rem; }
        .card h2 { margin:.1rem 0 .9rem; font-size:1.05rem; }
        table.data { width:100%; border-collapse:collapse; font-size:.88rem; }
        table.data th, table.data td { border-bottom:1px solid var(--line); padding:.5rem .6rem; text-align:left; }
        table.data th { background:#f1f5f9; font-weight:600; }
        .filters { display:flex; flex-wrap:wrap; gap:.75rem; align-items:end; margin-bottom:1rem; }
        .filters label { display:block; font-size:.78rem; color:var(--muted); margin-bottom:.2rem; }
        .filters input, .filters select { padding:.45rem .55rem; border:1px solid var(--line); border-radius:.4rem; font-size:.9rem; }
        .btn { background:var(--brand); border:0; color:#fff; padding:.5rem .9rem; border-radius:.4rem; cursor:pointer; font-size:.9rem; text-decoration:none; display:inline-block; }
        .btn.secondary { background:#fff; color:var(--ink); border:1px solid var(--line); }
        .alert { padding:.6rem .9rem; border-radius:.4rem; margin-bottom:1rem; font-size:.9rem; }
        .alert.success { background:#ecfdf5; color:#065f46; border:1px solid #a7f3d0; }
        .alert.error { background:#fef2f2; color:#991b1b; border:1px solid #fecaca; }
        .grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(240px,1fr)); gap:1rem; }
        .module { display:block; background:#fff; border:1px solid var(--line); border-radius:.6rem; padding:1rem; text-decoration:none; color:var(--ink); }
        .module:hover { border-color:var(--brand); }
        .module .desc { color:var(--muted); font-size:.82rem; margin-top:.3rem; }
        footer { text-align:center; color:var(--muted); font-size:.8rem; padding:1.5rem 0; }
    </style>
    @stack('styles')
</head>
<body>
<header>
    <a class="brand" href="{{ url('/') }}">{{ config('app.name') }}</a>
    @auth
        <span class="badge">{{ auth()->user()->role }}</span>
        <nav>
            <span style="color:var(--muted)">FY {{ \App\Support\FiscalYear::name() }}</span>
            @can('view-reports')
                <a href="{{ route('viewer.reports.index') }}">Reports</a>
            @endcan
            <form method="POST" action="{{ route('logout') }}" style="display:inline">
                @csrf
                <button class="btn secondary" type="submit">Logout ({{ auth()->user()->login }})</button>
            </form>
        </nav>
    @endauth
</header>
<main>
    @if (session('status'))
        <div class="alert success">{{ session('status') }}</div>
    @endif
    @if (session('warning'))
        <div class="alert error">{{ session('warning') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert error">{{ implode(' · ', $errors->all()) }}</div>
    @endif
    @yield('content')
</main>
<footer>Stores Management System — modernized Laravel edition</footer>
</body>
</html>
