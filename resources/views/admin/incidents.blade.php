@extends('admin.layout')

@section('title', 'Incidents')

@php($tz = config('election.timezone'))

@section('content')
    <div class="page-head">
        <div>
            <h1>Incidents</h1>
            <p class="muted">Reported by agents (USSD or web app) and by the public over USSD. Urgent types from agents also send an SMS alert to coordinators; public reports never do (they are unverified).</p>
        </div>
        <div class="actions">
            <a class="button" href="{{ route('admin.incidents.export', request()->query()) }}">Export (CSV)</a>
            <button type="button" class="secondary" onclick="window.print()">Print / Save PDF</button>
        </div>
    </div>

    <div class="tabs">
        <a href="{{ route('admin.incidents.index', [...request()->except('type', 'page')]) }}" @class(['on' => ! $filters['type']])>All <b>{{ number_format($counts->sum()) }}</b></a>
        @foreach ($types as $type)
            <a href="{{ route('admin.incidents.index', [...request()->except('type', 'page'), 'type' => $type->value]) }}" @class(['on' => $filters['type'] === $type->value])>{{ $type->label() }} <b>{{ number_format($counts[$type->value] ?? 0) }}</b></a>
        @endforeach
    </div>

    <div class="card no-print">
        <form method="get" class="filters">
            @if ($filters['type'])<input type="hidden" name="type" value="{{ $filters['type'] }}">@endif
            <div class="wide"><label>Search</label><input type="search" name="q" value="{{ $filters['q'] }}" placeholder="Reference, note, PU code or name, agent"></div>
            <div>
                <label>Reported by</label>
                <select name="source">
                    <option value="">Everyone</option>
                    <option value="agent" @selected($filters['source'] === 'agent')>Agents</option>
                    <option value="public" @selected($filters['source'] === 'public')>The public ({{ number_format($publicCount) }})</option>
                </select>
            </div>
            <div>
                <label>LGA</label>
                <select name="lga">
                    <option value="">All LGAs</option>
                    @foreach ($lgas as $lga)<option @selected($filters['lga'] === $lga)>{{ $lga }}</option>@endforeach
                </select>
            </div>
            <div class="actions" style="flex:0 0 auto"><button type="submit">Apply</button> <a class="button secondary" href="{{ route('admin.incidents.index') }}">Reset</a></div>
        </form>
    </div>

    <div class="card">
        <table class="data">
            <thead>
                <tr><th>Reference</th><th>Type</th><th>Note</th><th>Where</th><th>Reported by</th><th>Reported</th></tr>
            </thead>
            <tbody>
                @forelse ($incidents as $incident)
                    <tr>
                        <td><b>{{ $incident->reference }}</b></td>
                        <td>
                            <span class="badge {{ $incident->isUrgent() ? 'urgent' : 'neutral' }}">{{ $incident->type->label() }}</span>
                            @if ($incident->isPublic())<span class="badge neutral">Public (unverified)</span>@elseif ($incident->isUrgent())<span class="sub">Urgent: coordinators alerted</span>@endif
                        </td>
                        <td class="text">{{ $incident->note }}</td>
                        @if ($incident->pollingUnit)
                            <td class="text">{{ $incident->pollingUnit->name }}<span class="sub">{{ $incident->polling_unit_code }} · {{ $incident->pollingUnit->ward }}, {{ $incident->pollingUnit->lga }}</span></td>
                        @else
                            <td class="text">{{ $incident->ward ?? '—' }}<span class="sub">{{ $incident->lga }}{{ $incident->polling_unit_code ? ' · PU '.$incident->polling_unit_code : '' }}</span></td>
                        @endif
                        @if ($incident->agent)
                            <td>{{ $incident->agent->name }}<span class="sub">Agent · {{ $incident->agent->phone_number }}</span></td>
                        @else
                            <td>Member of the public<span class="sub">{{ $incident->reporter_phone }}</span></td>
                        @endif
                        <td>{{ $incident->created_at->timezone($tz)->format('j M, g:i A') }}<span class="sub">{{ $incident->created_at->diffForHumans() }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="empty">No incidents match these filters.</td></tr>
                @endforelse
            </tbody>
        </table>
        <div class="pagination">
            @if ($incidents->previousPageUrl())<a href="{{ $incidents->previousPageUrl() }}">← Previous</a>@endif
            <span class="muted">{{ number_format($incidents->total()) }} incident(s) · page {{ $incidents->currentPage() }} of {{ $incidents->lastPage() }}</span>
            @if ($incidents->nextPageUrl())<a href="{{ $incidents->nextPageUrl() }}">Next →</a>@endif
        </div>
    </div>
@endsection
