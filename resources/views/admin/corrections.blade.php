@extends('admin.layout')

@section('title', 'Corrections')

@php($figures = fn ($result) => collect($result->votesByParty())->map(fn ($votes, $party) => "{$party} ".number_format($votes))->implode(' · ').' · Rejected '.number_format($result->rejected_votes).' · Accredited '.number_format($result->accredited_voters))

@section('content')
    <h1>Corrections</h1>

    <div class="card">
        <h2>Waiting for review ({{ $pending->count() }})</h2>
        @forelse ($pending as $correction)
            <div style="border-top:1px solid var(--line);padding:14px 0">
                <p><b>{{ $correction->reference }}</b> · PU {{ $correction->polling_unit_code }}@if ($correction->pollingUnit) — {{ $correction->pollingUnit->name }}, {{ $correction->pollingUnit->lga }}@endif
                    <br><span class="muted">Requested by {{ $correction->agent->name }} ({{ $correction->agent->phone_number }}), {{ $correction->created_at->timezone(config('election.timezone'))->format('j M, g:i A') }}</span></p>
                <table>
                    @if ($correction->corrects)
                        <tr><th style="width:120px">Current</th><td>{{ $figures($correction->corrects) }} <span class="muted">({{ $correction->corrects->reference }} by {{ $correction->corrects->agent->name }})</span></td></tr>
                    @endif
                    <tr><th>Proposed</th><td><b>{{ $figures($correction) }}</b></td></tr>
                </table>
                <div class="row" style="margin-top:10px">
                    <form method="post" action="{{ route('admin.corrections.approve', $correction) }}" class="row">
                        @csrf
                        <input type="text" name="note" placeholder="Note (optional)">
                        <button type="submit">Approve</button>
                    </form>
                    <form method="post" action="{{ route('admin.corrections.reject', $correction) }}" class="row">
                        @csrf
                        <input type="text" name="note" placeholder="Reason (optional)">
                        <button type="submit" class="danger">Reject</button>
                    </form>
                </div>
            </div>
        @empty
            <p class="muted">Nothing waiting for review.</p>
        @endforelse
    </div>

    @if ($reviewed->isNotEmpty())
        <div class="card">
            <h2>Recently reviewed</h2>
            <table>
                <tr><th>Reference</th><th>PU</th><th>Agent</th><th>Decision</th><th>Note</th><th>When</th></tr>
                @foreach ($reviewed as $correction)
                    <tr>
                        <td>{{ $correction->reference }}</td>
                        <td>{{ $correction->polling_unit_code }}</td>
                        <td>{{ $correction->agent->name }}</td>
                        <td>{{ $correction->status === \App\Enums\ResultStatus::Rejected ? 'Rejected' : 'Approved' }}</td>
                        <td>{{ $correction->review_note ?? '—' }}</td>
                        <td>{{ $correction->reviewed_at->timezone(config('election.timezone'))->format('j M, g:i A') }}</td>
                    </tr>
                @endforeach
            </table>
        </div>
    @endif
@endsection
