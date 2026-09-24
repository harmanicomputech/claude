@extends('admin.layout')

@section('title', 'Overview')

@section('content')
    <h1>Overview</h1>

    @if ($summary)
        <div class="stats">
            <div class="stat"><b>{{ number_format($summary['polling_units']) }}</b><span>Polling units</span></div>
            <div class="stat"><b>{{ $summary['presence']['percent'] }}%</b><span>Agents checked in ({{ number_format($summary['presence']['polling_units']) }} PUs)</span></div>
            <div class="stat"><b>{{ $summary['results']['percent'] }}%</b><span>Results received ({{ number_format($summary['results']['polling_units']) }} PUs)</span></div>
            <div class="stat"><b>{{ number_format($summary['results']['pending_corrections']) }}</b><span>Corrections to review</span></div>
            <div class="stat"><b>{{ number_format($summary['incidents']['total']) }}</b><span>Incidents ({{ $summary['incidents']['last_hour'] }} last hour)</span></div>
        </div>

        <div class="card">
            <h2>Votes so far</h2>
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
@endsection
