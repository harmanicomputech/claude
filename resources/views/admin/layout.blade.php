<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('title') · Election Shield Admin</title>
    <style>
        :root { --bg:#f5f6f8; --card:#fff; --text:#1d2330; --muted:#667085; --line:#e4e7ec; --brand:#0f6e4f; --brand-2:#0b5a40; --ok:#067647; --ok-bg:#ecfdf3; --bad:#b42318; --bad-bg:#fef3f2; --warn:#b54708; --warn-bg:#fffaeb; }
        * { box-sizing:border-box; }
        body { margin:0; font:15px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif; background:var(--bg); color:var(--text); }
        header { background:var(--brand); color:#fff; }
        header .wrap { display:flex; align-items:center; gap:24px; flex-wrap:wrap; padding:12px 16px; }
        header strong { font-size:17px; }
        header nav { display:flex; gap:4px; flex-wrap:wrap; flex:1; }
        header nav a { color:#d1fadf; text-decoration:none; padding:6px 10px; border-radius:6px; }
        header nav a.active, header nav a:hover { background:var(--brand-2); color:#fff; }
        header form button { background:none; border:1px solid #9fe0c0; color:#fff; }
        .wrap { max-width:1100px; margin:0 auto; padding:24px 16px; }
        h1 { font-size:22px; margin:0 0 16px; }
        h2 { font-size:17px; margin:0 0 12px; }
        .card { background:var(--card); border:1px solid var(--line); border-radius:10px; padding:20px; margin-bottom:20px; overflow-x:auto; }
        .grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(280px,1fr)); gap:20px; }
        .grid .card { margin-bottom:0; }
        .stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:12px; margin-bottom:20px; }
        .stat { background:var(--card); border:1px solid var(--line); border-radius:10px; padding:14px; }
        .stat b { display:block; font-size:24px; }
        .stat span { color:var(--muted); font-size:13px; }
        table { width:100%; border-collapse:collapse; }
        th, td { text-align:left; padding:8px 10px; border-bottom:1px solid var(--line); vertical-align:top; }
        th { font-size:13px; color:var(--muted); font-weight:600; }
        td.num, th.num { text-align:right; font-variant-numeric:tabular-nums; }
        label { display:block; font-size:13px; color:var(--muted); margin:10px 0 4px; }
        label.inline { display:inline-flex; gap:6px; align-items:center; color:var(--text); font-size:14px; }
        input[type=text], input[type=password], input[type=email], input[type=search], select { width:100%; padding:8px 10px; border:1px solid #d0d5dd; border-radius:6px; font:inherit; background:#fff; }
        input[type=file] { font:inherit; }
        button, .button { display:inline-block; padding:8px 14px; border-radius:6px; border:1px solid var(--brand); background:var(--brand); color:#fff; font:inherit; cursor:pointer; text-decoration:none; }
        button.secondary, .button.secondary { background:#fff; color:var(--brand); }
        button.danger { background:#fff; color:var(--bad); border-color:#fda29b; }
        .row { display:flex; gap:8px; flex-wrap:wrap; align-items:end; }
        .row > * { flex:1; min-width:140px; }
        .row > button, .row > .button { flex:0 0 auto; }
        .flash { padding:12px 16px; border-radius:8px; margin-bottom:16px; }
        .flash.ok { background:var(--ok-bg); color:var(--ok); border:1px solid #abefc6; }
        .flash.bad { background:var(--bad-bg); color:var(--bad); border:1px solid #fecdca; }
        pre { background:#101828; color:#e4e7ec; padding:12px; border-radius:8px; overflow:auto; font-size:13px; white-space:pre-wrap; }
        code { background:#f2f4f7; padding:1px 5px; border-radius:4px; font-size:13px; word-break:break-all; }
        .badge { display:inline-block; font-size:12px; padding:2px 8px; border-radius:999px; font-weight:600; }
        .badge.ok { background:var(--ok-bg); color:var(--ok); }
        .badge.bad { background:var(--bad-bg); color:var(--bad); }
        .badge.info { background:var(--warn-bg); color:var(--warn); }
        .muted { color:var(--muted); }
        details summary { cursor:pointer; color:var(--brand); }
        .pagination { display:flex; gap:12px; margin-top:12px; }
        @media (max-width: 640px) {
            table.checks tr { display:block; padding:8px 0; border-bottom:1px solid var(--line); }
            table.checks td { display:inline-block; border:0; padding:2px 6px 2px 0; width:auto !important; }
            table.checks td.muted { display:block; }
            header .wrap { gap:10px; }
        }
    </style>
</head>
<body>
@if (session('admin.authenticated'))
    <header>
        <div class="wrap" style="padding-top:12px;padding-bottom:12px">
            <strong>Election Shield</strong>
            <nav>
                @foreach (['admin.overview' => 'Overview', 'admin.agents.index' => 'Agents', 'admin.coordinators.index' => 'Coordinators', 'admin.corrections.index' => 'Corrections', 'admin.missing' => 'Missing PUs'] as $route => $label)
                    <a href="{{ route($route) }}" @class(['active' => request()->routeIs(Str::before($route, '.index').'*')])>{{ $label }}</a>
                @endforeach
            </nav>
            <form method="post" action="{{ route('admin.logout') }}">@csrf<button type="submit">Log out</button></form>
        </div>
    </header>
@endif
<main class="wrap">
    @if (session('status'))
        <div class="flash ok">{{ session('status') }}</div>
    @endif
    @if (session('error'))
        <div class="flash bad">{{ session('error') }}</div>
    @endif
    @if ($errors->any())
        <div class="flash bad">{{ implode(' ', $errors->all()) }}</div>
    @endif
    @if (session('output'))
        <pre>{{ session('output') }}</pre>
    @endif

    @yield('content')
</main>
</body>
</html>
