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
        .pagination { display:flex; gap:12px; margin-top:12px; align-items:center; flex-wrap:wrap; }
        a { color:var(--brand); }
        a.stat { text-decoration:none; color:inherit; transition:border-color .15s, box-shadow .15s, transform .15s; }
        a.stat:hover { border-color:var(--brand); box-shadow:0 4px 14px rgba(15,110,79,.12); transform:translateY(-1px); }
        a.stat span::after { content:" →"; color:var(--brand); }
        .page-head { display:flex; justify-content:space-between; align-items:flex-end; gap:12px; flex-wrap:wrap; margin-bottom:16px; }
        .page-head h1 { margin:0; }
        .page-head p { margin:4px 0 0; }
        .actions { display:flex; gap:8px; flex-wrap:wrap; }
        .filters { display:flex; gap:10px; flex-wrap:wrap; align-items:end; }
        .filters > div { flex:1 1 160px; }
        .filters > div.wide { flex:2 1 240px; }
        .filters label { margin-top:0; }
        .tabs { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:14px; }
        .tabs a { padding:6px 12px; border-radius:999px; border:1px solid var(--line); background:#fff; color:var(--text); text-decoration:none; font-size:14px; }
        .tabs a.on { background:var(--brand); border-color:var(--brand); color:#fff; }
        .tabs a b { font-weight:600; margin-left:4px; opacity:.8; }
        .badge.neutral { background:#f2f4f7; color:#344054; }
        .badge.accepted { background:var(--ok-bg); color:var(--ok); }
        .badge.pending { background:var(--warn-bg); color:var(--warn); }
        .badge.rejected, .badge.urgent { background:var(--bad-bg); color:var(--bad); }
        .badge.superseded { background:#f2f4f7; color:#475467; }
        table.data td, table.data th { white-space:nowrap; }
        table.data td.text { white-space:normal; min-width:200px; }
        table.data tbody tr:hover { background:#f9fafb; }
        tr.total td { font-weight:600; border-top:2px solid var(--line); }
        .sub { display:block; color:var(--muted); font-size:13px; }
        /* Party share bars: one hue, direct labels, value in text ink. */
        .bars { display:grid; gap:10px; }
        .bar-row { display:grid; grid-template-columns:minmax(120px, 220px) 1fr 110px; gap:12px; align-items:center; }
        .bar-row .who b { display:block; }
        .bar-track { height:14px; background:#f2f4f7; border-radius:4px; overflow:hidden; }
        .bar-fill { height:100%; background:#2a78d6; border-radius:0 4px 4px 0; min-width:2px; }
        .bar-row .val { text-align:right; font-variant-numeric:tabular-nums; }
        .bar-row .val small { display:block; color:var(--muted); }
        .sheet { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:12px; }
        .sheet div { border:1px solid var(--line); border-radius:8px; padding:10px 12px; }
        .sheet dt { color:var(--muted); font-size:13px; }
        .sheet dd { margin:2px 0 0; font-size:18px; font-weight:600; font-variant-numeric:tabular-nums; }
        .empty { text-align:center; padding:28px; color:var(--muted); }
        @media print {
            header, .no-print, .filters, .actions, .tabs, .pagination, .flash { display:none !important; }
            body { background:#fff; font-size:12px; }
            .wrap { max-width:none; padding:0; }
            .card { border:0; padding:0; margin-bottom:14px; overflow:visible; }
            table.data td, table.data th { padding:4px 6px; }
            a { color:inherit; text-decoration:none; }
        }
        @media (max-width: 640px) {
            .bar-row { grid-template-columns:1fr 90px; }
            .bar-row .bar-track { grid-column:1 / -1; grid-row:2; }
            table.checks tr { display:block; padding:8px 0; border-bottom:1px solid var(--line); }
            table.checks td { display:inline-block; border:0; padding:2px 6px 2px 0; width:auto !important; }
            table.checks td.muted { display:block; }
            header .wrap { gap:10px; }
        }
    </style>
</head>
<body>
@auth
    @php
        $nav = ['admin.overview' => 'Overview', 'admin.results.index' => 'Results', 'admin.incidents.index' => 'Incidents', 'admin.polling-units.index' => 'Polling units', 'admin.agents.index' => 'Agents', 'admin.corrections.index' => 'Corrections', 'admin.coordinators.index' => 'Coordinators'];
        if (auth()->user()->isAdmin()) {
            $nav += ['admin.users.index' => 'Users', 'admin.audit.index' => 'Audit log', 'admin.settings.index' => 'Settings'];
        }
    @endphp
    <header>
        <div class="wrap" style="padding-top:12px;padding-bottom:12px">
            <strong>Election Shield</strong>
            <nav>
                @foreach ($nav as $route => $label)
                    <a href="{{ route($route) }}" @class(['active' => request()->routeIs(Str::before($route, '.index').'*')])>{{ $label }}</a>
                @endforeach
            </nav>
            <span style="font-size:13px;color:#d1fadf">{{ auth()->user()->name }} · {{ auth()->user()->role->label() }}</span>
            <form method="post" action="{{ route('admin.logout') }}">@csrf<button type="submit">Log out</button></form>
        </div>
    </header>
    @if (\App\Support\Rehearsal::active())
        <div class="no-print" style="background:#fffaeb;color:#b54708;border-bottom:1px solid #fedf89;text-align:center;padding:8px 16px;font-weight:600">
            REHEARSAL MODE: submissions are practice data. Clear them in Settings before election day.
        </div>
    @endif
@endauth
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
