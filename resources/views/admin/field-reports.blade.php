@extends('admin.layout')

@section('title', $tab === 'materials' ? 'Materials' : 'Check-ins')

@php($tz = config('election.timezone'))
@php($keep = array_filter(['q' => $filters['q'] ?: null, 'lga' => $filters['lga'], 'ward' => $filters['ward']]))

@section('content')
    <div class="page-head">
        <div>
            <h1>Check-ins &amp; materials</h1>
            <p class="muted">Agents confirming they are at their polling unit, and whether the materials and the INEC officials (personnel) have arrived, from USSD and the web app. Materials show each PU's latest report.</p>
        </div>
        <div class="actions">
            <a class="button" href="{{ route('admin.field-reports.export', [$tab, ...request()->query()]) }}">Export (CSV)</a>
        </div>
    </div>

    <div class="stats">
        <div class="stat"><b>{{ number_format($checkedIn) }} / {{ number_format($units) }}</b><span>PUs with an agent checked in</span></div>
        <div class="stat"><b>{{ number_format($reported) }} / {{ number_format($units) }}</b><span>PUs with a materials report</span></div>
    </div>

    <div class="tabs">
        <a href="{{ route('admin.checkins.index', $keep) }}" @class(['on' => $tab === 'checkins'])>Check-ins</a>
        <a href="{{ route('admin.materials.index', $keep) }}" @class(['on' => $tab === 'materials'])>Materials &amp; personnel</a>
    </div>

    @if ($tab === 'materials')
        <div class="tabs">
            @foreach (['all' => 'All', 'arrived' => 'Materials arrived', 'incomplete' => 'Incomplete', 'not_arrived' => 'Not arrived', 'personnel_missing' => 'No personnel'] as $key => $label)
                <a href="{{ route('admin.materials.index', [...$keep, 'status' => $key === 'all' ? null : $key]) }}" @class(['on' => $filters['status'] === $key])>{{ $label }} <b>{{ number_format($statusCounts[$key]) }}</b></a>
            @endforeach
        </div>
    @endif

    <div class="card no-print">
        <form method="get" class="filters">
            @if ($tab === 'materials' && $filters['status'] !== 'all')<input type="hidden" name="status" value="{{ $filters['status'] }}">@endif
            <div class="wide"><label>Search</label><input type="search" name="q" value="{{ $filters['q'] }}" placeholder="PU code or name, agent"></div>
            <div>
                <label>LGA</label>
                <select name="lga">
                    <option value="">All</option>
                    @foreach ($lgas as $lga)<option @selected($filters['lga'] === $lga)>{{ $lga }}</option>@endforeach
                </select>
            </div>
            @if ($wards->isNotEmpty())
                <div>
                    <label>Ward</label>
                    <select name="ward">
                        <option value="">All</option>
                        @foreach ($wards as $ward)<option @selected($filters['ward'] === $ward)>{{ $ward }}</option>@endforeach
                    </select>
                </div>
            @endif
            <div class="actions" style="flex:0 0 auto"><button type="submit">Apply</button> <a class="button secondary" href="{{ route($tab === 'materials' ? 'admin.materials.index' : 'admin.checkins.index') }}">Reset</a></div>
        </form>
    </div>

    <div class="card">
        <table class="data">
            <thead>
                <tr><th>Polling unit</th>@if ($tab === 'materials')<th>Materials</th><th>Personnel</th>@endif<th>Agent</th><th>{{ $tab === 'materials' ? 'Reported' : 'Checked in' }}</th></tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr>
                        <td class="text">{{ $row->pollingUnit?->name ?? 'PU '.$row->polling_unit_code }}<span class="sub">{{ $row->polling_unit_code }}@if ($row->pollingUnit) · {{ $row->pollingUnit->ward }}, {{ $row->pollingUnit->lga }}@endif</span></td>
                        @if ($tab === 'materials')
                            <td><span class="badge {{ match ($row->status->value) { 'arrived' => 'ok', 'incomplete' => 'pending', default => 'urgent' } }}">{{ $row->status->label() }}</span></td>
                            <td><span class="badge {{ $row->personnel_arrived === true ? 'ok' : ($row->personnel_arrived === false ? 'urgent' : 'neutral') }}">{{ $row->personnelLabel() }}</span></td>
                        @endif
                        <td>{{ $row->agent?->name ?? '—' }}<span class="sub">{{ $row->agent?->phone_number }} · {{ ($row->channel ?? 'ussd') === 'web' ? 'Web app' : 'USSD' }}</span></td>
                        @php($at = $tab === 'materials' ? $row->reported_at : $row->confirmed_at)
                        <td>{{ $at->timezone($tz)->format('j M, g:i A') }}<span class="sub">{{ $at->diffForHumans() }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="{{ $tab === 'materials' ? 5 : 3 }}" class="empty">Nothing reported yet{{ $filters['q'] || $filters['lga'] ? ' for these filters' : '' }}.</td></tr>
                @endforelse
            </tbody>
        </table>
        <div class="pagination">
            @if ($rows->previousPageUrl())<a href="{{ $rows->previousPageUrl() }}">← Previous</a>@endif
            <span class="muted">{{ number_format($rows->total()) }} report(s) · page {{ $rows->currentPage() }} of {{ $rows->lastPage() }}</span>
            @if ($rows->nextPageUrl())<a href="{{ $rows->nextPageUrl() }}">Next →</a>@endif
        </div>
    </div>
@endsection
