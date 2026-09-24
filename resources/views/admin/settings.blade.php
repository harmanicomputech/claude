@extends('admin.layout')

@section('title', 'Settings')

@section('content')
    <h1>Settings</h1>

    <div class="card">
        <h2>Rehearsal mode <span class="badge {{ $rehearsal ? 'pending' : 'neutral' }}">{{ $rehearsal ? 'ON' : 'OFF' }}</span></h2>
        <p class="muted">Use this for practice runs with your agents. While it is on:</p>
        <ul class="muted" style="margin-top:0">
            <li>presence check-in and result submission are open at any time</li>
            <li>the USSD menu reads <b>Election Shield REHEARSAL</b>, and every SMS and email starts with <b>[REHEARSAL]</b></li>
            <li>dashboard events carry <code>"rehearsal": true</code></li>
        </ul>
        <form method="post" action="{{ route('admin.settings.rehearsal') }}">
            @csrf
            <input type="hidden" name="on" value="{{ $rehearsal ? 0 : 1 }}">
            <button type="submit" @class(['secondary' => $rehearsal])>{{ $rehearsal ? 'Switch rehearsal mode off' : 'Switch rehearsal mode on' }}</button>
        </form>
    </div>

    <div class="card">
        <h2>Results dashboard</h2>
        @if ($dashboardUrl)
            <p class="muted">Connected to <code>{{ $dashboardUrl }}</code>. New submissions are sent automatically. If the dashboard was connected after data was already collected, send it everything that already exists. Anything it already has is skipped.</p>
            <form method="post" action="{{ route('admin.settings.backfill') }}">@csrf<button type="submit" class="secondary">Send all existing data to the dashboard</button></form>
        @else
            <p class="muted">Not connected. Set <code>DASHBOARD_WEBHOOK_URL</code> (and <code>DASHBOARD_API_TOKEN</code>, <code>DASHBOARD_WEBHOOK_SECRET</code>) in <code>election-shield/.env</code>.</p>
        @endif
    </div>

    <div class="card" style="border-color:#fda29b">
        <h2>Clear test data</h2>
        <p>Removes <b>{{ number_format($counts['results']) }} result(s), {{ number_format($counts['incidents']) }} incident(s) and {{ number_format($counts['check-ins']) }} check-in(s)</b>, plus queued SMS/emails and dashboard events. It keeps the polling units, coordinators, console accounts and the audit log, and unlocks every agent.</p>

        @if ($clearBlocked)
            <p class="flash bad">{{ $clearBlocked }}</p>
        @else
            <form method="post" action="{{ route('admin.settings.clear') }}" class="filters" onsubmit="return confirm('Permanently delete all results, incidents and check-ins?')">
                @csrf
                <div><label>Type <b>CLEAR</b> to confirm</label><input type="text" name="confirm" autocomplete="off" required></div>
                <div><label>Your password</label><input type="password" name="password" required></div>
                <div style="flex:0 0 auto"><label class="inline"><input type="checkbox" name="remove_agents" value="1"> Also remove all agents ({{ number_format($counts['agents']) }})</label></div>
                <div class="actions" style="flex:0 0 auto"><button type="submit" class="danger">Clear test data</button></div>
            </form>
        @endif
    </div>

    <div class="card">
        <h2>Election settings</h2>
        <p class="muted">Set in <code>election-shield/.env</code>; shown here for checking.</p>
        <table>
            <tr><th>Election</th><td>{{ config('election.name') }}</td></tr>
            <tr><th>Date</th><td>{{ $calendar->date()->format('l j F Y') }}</td></tr>
            <tr><th>Presence opens</th><td>{{ $calendar->format($calendar->presenceOpensAt()) }}</td></tr>
            <tr><th>Results open</th><td>{{ $calendar->format($calendar->resultsOpenAt()) }}</td></tr>
            <tr><th>Results close</th><td>{{ $calendar->resultsCloseAt() ? $calendar->format($calendar->resultsCloseAt()) : 'Never' }}</td></tr>
            <tr><th>Windows enforced</th><td>{{ config('election.enforce_windows') ? 'Yes' : 'No (ELECTION_ENFORCE_WINDOWS=false)' }}{{ $rehearsal ? ' · paused by rehearsal mode' : '' }}</td></tr>
            <tr><th>Parties</th><td>{{ implode(', ', config('election.parties')) }}</td></tr>
            <tr><th>USSD code</th><td>{{ config('ussd.service_code') }}</td></tr>
        </table>
    </div>
@endsection
