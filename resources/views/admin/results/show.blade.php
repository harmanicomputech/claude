@extends('admin.layout')

@section('title', $result->reference)

@php
    $unit = $result->pollingUnit;
    $tz = config('election.timezone');
    $votes = $result->votesByParty();
    $max = max(1, max($votes ?: [0]));
@endphp

@section('content')
    <p class="no-print"><a href="{{ route('admin.results.index') }}">← All results</a></p>

    <div class="page-head">
        <div>
            <h1>{{ $result->reference }} <span class="badge {{ $result->status->value }}" style="vertical-align:middle">{{ ucfirst($result->status->value) }}</span></h1>
            <p class="muted">{{ $unit?->name ?? 'Unknown PU' }} · PU {{ $result->polling_unit_code }}@if ($unit) · {{ $unit->ward }}, {{ $unit->lga }} LGA @endif</p>
        </div>
        <div class="actions"><button type="button" class="secondary" onclick="window.print()">Print / Save PDF</button></div>
    </div>

    @if ($result->status->value === 'pending')
        <div class="flash bad no-print">This correction is waiting for review. <a href="{{ route('admin.corrections.index') }}">Review it on the Corrections page</a>.</div>
    @endif

    <div class="card">
        <h2>Result sheet (EC8A)</h2>
        <dl class="sheet">
            <div><dt>Registered voters</dt><dd>{{ $unit?->registered_voters !== null ? number_format($unit->registered_voters) : '—' }}</dd></div>
            <div><dt>Accredited voters</dt><dd>{{ number_format($result->accredited_voters) }}</dd></div>
            <div><dt>Turnout</dt><dd>{{ $unit?->registered_voters ? round($result->accredited_voters / $unit->registered_voters * 100, 1).'%' : '—' }}</dd></div>
            <div><dt>Total valid votes</dt><dd>{{ number_format($result->total_valid_votes) }}</dd></div>
            <div><dt>Rejected votes</dt><dd>{{ number_format($result->rejected_votes) }}</dd></div>
            <div><dt>Total votes cast</dt><dd>{{ number_format($result->total_votes_cast) }}</dd></div>
        </dl>

        <h2 style="margin-top:22px">Votes by party</h2>
        <div class="bars">
            @foreach ($votes as $party => $count)
                @php($share = $result->total_valid_votes ? round($count / $result->total_valid_votes * 100, 1) : 0)
                <div class="bar-row" title="{{ $party }}: {{ number_format($count) }} votes ({{ $share }}%)">
                    <div class="who"><b>{{ $party }}</b><span class="sub">{{ $candidates[$party] ?? 'All other parties' }}</span></div>
                    <div class="bar-track"><div class="bar-fill" style="width: {{ $count / $max * 100 }}%"></div></div>
                    <div class="val"><b>{{ number_format($count) }}</b><small>{{ $share }}%</small></div>
                </div>
            @endforeach
        </div>
    </div>

    <div class="grid">
        <div class="card">
            <h2>Submission</h2>
            <table>
                <tr><th>Agent</th><td>{{ $result->agent->name }}<span class="sub">{{ $result->agent->phone_number }}</span></td></tr>
                <tr><th>Submitted</th><td>{{ $result->created_at->timezone($tz)->format('j M Y, g:i A') }}</td></tr>
                @if ($result->corrects)
                    <tr><th>Replaces</th><td><a href="{{ route('admin.results.show', $result->corrects) }}">{{ $result->corrects->reference }}</a></td></tr>
                @endif
                @if ($result->reviewed_at)
                    <tr><th>Reviewed</th><td>{{ $result->reviewed_at->timezone($tz)->format('j M Y, g:i A') }}@if ($result->reviewed_by) by {{ $result->reviewed_by }}@endif</td></tr>
                @endif
                @if ($result->review_note)
                    <tr><th>Note</th><td>{{ $result->review_note }}</td></tr>
                @endif
            </table>
        </div>

        <div class="card">
            <h2>History for this polling unit</h2>
            <table class="data">
                <tr><th>Reference</th><th>Submitted</th><th class="num">Valid</th><th>Status</th></tr>
                @foreach ($history as $entry)
                    <tr @if ($entry->is($result)) style="background:#f0f9f4" @endif>
                        <td><a href="{{ route('admin.results.show', $entry) }}">{{ $entry->reference }}</a><span class="sub">{{ $entry->agent->name }}</span></td>
                        <td>{{ $entry->created_at->timezone($tz)->format('j M, g:i A') }}</td>
                        <td class="num">{{ number_format($entry->total_valid_votes) }}</td>
                        <td><span class="badge {{ $entry->status->value }}">{{ ucfirst($entry->status->value) }}</span></td>
                    </tr>
                @endforeach
            </table>
        </div>
    </div>
@endsection
