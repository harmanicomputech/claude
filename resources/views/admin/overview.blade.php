@extends('admin.layout')

@section('title', 'Overview')

@section('content')
    <h1>Overview</h1>

    @if ($summary)
        <div class="stats">
            <a class="stat" href="{{ route('admin.polling-units.index') }}"><b>{{ number_format($summary['polling_units']) }}</b><span>Polling units</span></a>
            <a class="stat" href="{{ route('admin.agents.index', ['status' => 'checked_in']) }}"><b>{{ $summary['presence']['percent'] }}%</b><span>Agents checked in ({{ number_format($summary['presence']['polling_units']) }} PUs)</span></a>
            <a class="stat" href="{{ route('admin.polling-units.index', ['status' => 'materials_problem']) }}"><b>{{ number_format($summary['materials']['arrived']) }}</b><span>PUs with materials ({{ number_format($summary['materials']['incomplete']) }} incomplete, {{ number_format($summary['materials']['not_arrived']) }} not arrived)</span></a>
            <a class="stat" href="{{ route('admin.results.index') }}"><b>{{ $summary['results']['percent'] }}%</b><span>Results received ({{ number_format($summary['results']['polling_units']) }} PUs)</span></a>
            <a class="stat" href="{{ route('admin.corrections.index') }}"><b>{{ number_format($summary['results']['pending_corrections']) }}</b><span>Corrections to review</span></a>
            <a class="stat" href="{{ route('admin.incidents.index') }}"><b>{{ number_format($summary['incidents']['total']) }}</b><span>Incidents ({{ $summary['incidents']['last_hour'] }} last hour)</span></a>
        </div>

        <div class="card">
            <div class="page-head" style="margin-bottom:8px"><h2 style="margin:0">Votes so far</h2><a href="{{ route('admin.results.index') }}">Full results, collation &amp; export →</a></div>
            <table>
                <tr><th>Party</th><th>Candidate</th><th class="num">Votes</th></tr>
                @foreach ($summary['results']['party_votes'] as $party => $votes)
                    <tr><td>{{ $party }}</td><td>{{ $summary['results']['candidates'][$party] ?? '' }}</td><td class="num">{{ number_format($votes) }}</td></tr>
                @endforeach
                <tr><td><b>Total valid</b></td><td></td><td class="num"><b>{{ number_format($summary['results']['total_valid_votes']) }}</b></td></tr>
                <tr><td>Rejected</td><td></td><td class="num">{{ number_format($summary['results']['rejected_votes']) }}</td></tr>
            </table>
        </div>
    @endif

    <div class="card">
        <h2>Set-up checklist</h2>
        <table class="checks">
            @foreach ($checks as $check)
                <tr>
                    <td style="width:34%">{{ $check['label'] }}</td>
                    <td style="width:90px">
                        @if ($check['ok'] === true) <span class="badge ok">OK</span>
                        @elseif ($check['ok'] === false) <span class="badge bad">Needs attention</span>
                        @else <span class="badge info">Info</span>
                        @endif
                    </td>
                    <td class="muted">{{ $check['detail'] }}</td>
                </tr>
            @endforeach
        </table>
        <p class="muted">USSD callback URL for Africa's Talking:<br><code>{{ $callbackUrl }}</code></p>
    </div>

    @if ($jobs)
        <div class="card">
            <h2>Background jobs (SMS, email, dashboard)</h2>
            <p class="muted">{{ number_format($jobs['pending']) }} waiting · {{ number_format($jobs['failed']) }} failed. The cron job sends these every minute; you can also send them now.</p>
            @if (auth()->user()->isAdmin())
            <div class="row" style="justify-content:flex-start">
                <form method="post" action="{{ route('admin.system.jobs.run') }}">@csrf<button type="submit">Run background jobs now</button></form>
                @if ($jobs['failed'])
                    <form method="post" action="{{ route('admin.system.jobs.retry') }}">@csrf<button type="submit" class="secondary">Retry failed jobs</button></form>
                @endif
            </div>
            @endif
            @if ($jobs['failures'])
                <table style="margin-top:12px">
                    <tr><th>Failed job</th><th>When</th><th>Error</th></tr>
                    @foreach ($jobs['failures'] as $failure)
                        <tr><td>{{ $failure['job'] }}</td><td class="muted">{{ $failure['failed_at'] }}</td><td style="word-break:break-word">{{ $failure['error'] }}</td></tr>
                    @endforeach
                </table>
            @endif
        </div>
    @endif

    @if (auth()->user()->isAdmin())
    <div class="grid">
        <div class="card">
            <h2>Database</h2>
            <p class="muted">Creates or updates the tables. Safe to press again after uploading a new version.</p>
            <form method="post" action="{{ route('admin.system.migrate') }}">@csrf<button type="submit">Set up / update database</button></form>
        </div>

        <div class="card">
            <h2>Polling unit register</h2>
            <form method="post" action="{{ route('admin.system.polling-units') }}" enctype="multipart/form-data">
                @csrf
                <p class="muted">Loads the bundled Ebonyi register (3,308 PUs), or upload an updated CSV (<code>code,name,ward,lga,registered_voters</code>). Existing PUs are updated.</p>
                <input type="file" name="file" accept=".csv,text/csv">
                <p><button type="submit" @disabled(! $ready)>Import polling units</button></p>
            </form>
        </div>

        <div class="card">
            <h2>Email</h2>
            <form method="post" action="{{ route('admin.system.test-email') }}">
                @csrf
                <label for="to">Send a test email to (blank = NOTIFY_EMAILS)</label>
                <input type="email" id="to" name="to">
                <p class="row"><button type="submit">Send test email</button></p>
            </form>
            <form method="post" action="{{ route('admin.system.summary') }}">@csrf<button type="submit" class="secondary" @disabled(! $ready)>Email summary now</button></form>
        </div>
    </div>
    @endif

    <div class="card" style="margin-top:20px">
        <h2>Your account</h2>
        <form method="post" action="{{ route('admin.account.password') }}" class="filters">
            @csrf
            <div><label>Current password</label><input type="password" name="current_password" required></div>
            <div><label>New password (10+ characters)</label><input type="password" name="password" required></div>
            <div><label>Repeat new password</label><input type="password" name="password_confirmation" required></div>
            <div class="actions" style="flex:0 0 auto"><button type="submit" class="secondary">Change password</button></div>
        </form>
    </div>
@endsection
