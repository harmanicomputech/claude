@extends('admin.layout')

@section('title', 'Results')

@php
    $scopeLabel = $filters['ward'] ? "{$filters['ward']}, {$filters['lga']}" : ($filters['lga'] ? "{$filters['lga']} LGA" : 'Ebonyi State');
    $statusLabels = ['accepted' => 'Accepted', 'pending' => 'Pending corrections', 'superseded' => 'Superseded', 'rejected' => 'Rejected', 'all' => 'All submissions'];
    $turnout = fn ($result) => $result->pollingUnit?->registered_voters ? round($result->accredited_voters / $result->pollingUnit->registered_voters * 100, 1) : null;
@endphp

@section('content')
    <div class="page-head">
        <div>
            <h1>Results</h1>
            <p class="muted">{{ $scopeLabel }} · {{ $statusLabels[$filters['status']] }} · as of {{ now(config('election.timezone'))->format('j M Y, g:i A') }}</p>
        </div>
        <div class="actions">
            <a class="button" href="{{ route('admin.results.export', request()->query()) }}">Export results (CSV)</a>
            <a class="button secondary" href="{{ route('admin.results.export-breakdown', request()->query()) }}">Export collation (CSV)</a>
            <button type="button" class="secondary" onclick="window.print()">Print / Save PDF</button>
        </div>
    </div>

    <div class="card no-print">
        <form method="get" class="filters" id="filters">
            <div class="wide"><label>Search</label><input type="search" name="q" value="{{ $filters['q'] }}" placeholder="Reference, PU code or name, agent"></div>
            <div>
                <label>LGA</label>
                <select name="lga" id="lga">
                    <option value="">All LGAs</option>
                    @foreach ($lgas as $lga)<option @selected($filters['lga'] === $lga)>{{ $lga }}</option>@endforeach
                </select>
            </div>
            <div>
                <label>Ward</label>
                <select name="ward" id="ward" @disabled(! $filters['lga'])>
                    <option value="">All wards</option>
                    @foreach ($wardsByLga[$filters['lga']] ?? [] as $ward)<option @selected($filters['ward'] === $ward)>{{ $ward }}</option>@endforeach
                </select>
            </div>
            <div>
                <label>Status</label>
                <select name="status">
                    @foreach ($statusLabels as $value => $label)<option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>@endforeach
                </select>
            </div>
            <div>
                <label>Sort</label>
                <select name="sort">
                    <option value="newest" @selected($filters['sort'] === 'newest')>Newest first</option>
                    <option value="oldest" @selected($filters['sort'] === 'oldest')>Oldest first</option>
                    <option value="pu" @selected($filters['sort'] === 'pu')>PU code</option>
                </select>
            </div>
            <div class="actions" style="flex:0 0 auto"><button type="submit">Apply</button> <a class="button secondary" href="{{ route('admin.results.index') }}">Reset</a></div>
        </form>
    </div>

    <div class="stats">
        <div class="stat"><b>{{ number_format($totals['units']) }} / {{ number_format($totals['units_in_scope']) }}</b><span>PUs with results{{ $totals['units_in_scope'] ? ' ('.round($totals['units'] / $totals['units_in_scope'] * 100, 1).'%)' : '' }}</span></div>
        <div class="stat"><b>{{ number_format($totals['valid']) }}</b><span>Total valid votes</span></div>
        <div class="stat"><b>{{ number_format($totals['rejected']) }}</b><span>Rejected votes</span></div>
        <div class="stat"><b>{{ number_format($totals['accredited']) }}</b><span>Accredited voters</span></div>
    </div>

    <div class="card">
        <h2>Votes by party</h2>
        @if ($totals['results'] === 0)
            <p class="empty">No results match these filters yet.</p>
        @else
            <div class="bars" role="list">
                @foreach ($totals['parties'] as $party => $votes)
                    @php($share = $totals['valid'] ? round($votes / $totals['valid'] * 100, 1) : 0)
                    <div class="bar-row" role="listitem" title="{{ $party }}: {{ number_format($votes) }} votes ({{ $share }}% of valid votes)">
                        <div class="who"><b>{{ $party }}</b><span class="sub">{{ $candidates[$party] ?? 'All other parties' }}</span></div>
                        <div class="bar-track"><div class="bar-fill" style="width: {{ $votes / $totals['max'] * 100 }}%"></div></div>
                        <div class="val"><b>{{ number_format($votes) }}</b><small>{{ $share }}%</small></div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    <div class="card">
        <div class="page-head" style="margin-bottom:8px">
            <h2 style="margin:0">Collation by {{ $breakdown['level'] === 'ward' ? 'ward' : 'LGA' }} <span class="muted" style="font-weight:400;font-size:14px">(accepted results)</span></h2>
        </div>
        <table class="data">
            <thead>
                <tr>
                    <th>{{ $breakdown['level'] === 'ward' ? 'Ward' : 'LGA' }}</th>
                    <th class="num">PUs reported</th>
                    @foreach ($parties as $party)<th class="num">{{ $party }}</th>@endforeach
                    <th class="num">Total valid</th>
                    <th class="num">Rejected</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($breakdown['rows'] as $row)
                    <tr>
                        <td>
                            @if ($breakdown['level'] === 'lga')
                                <a href="{{ route('admin.results.index', ['lga' => $row['area'], 'status' => $filters['status']]) }}">{{ $row['area'] }}</a>
                            @else
                                <a href="{{ route('admin.results.index', ['lga' => $filters['lga'], 'ward' => $row['area'], 'status' => $filters['status']]) }}">{{ $row['area'] }}</a>
                            @endif
                        </td>
                        <td class="num">{{ number_format($row['reported']) }} / {{ number_format($row['units']) }} <span class="sub">{{ $row['percent'] }}%</span></td>
                        @foreach ($parties as $party)<td class="num">{{ number_format($row['votes'][$party] ?? 0) }}</td>@endforeach
                        <td class="num">{{ number_format($row['valid']) }}</td>
                        <td class="num">{{ number_format($row['rejected']) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                @php($all = collect($breakdown['rows']))
                <tr class="total">
                    <td>Total</td>
                    <td class="num">{{ number_format($all->sum('reported')) }} / {{ number_format($all->sum('units')) }}</td>
                    @foreach ($parties as $party)<td class="num">{{ number_format($all->sum(fn ($row) => $row['votes'][$party] ?? 0)) }}</td>@endforeach
                    <td class="num">{{ number_format($all->sum('valid')) }}</td>
                    <td class="num">{{ number_format($all->sum('rejected')) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>

    <div class="card">
        <h2>Submitted results <span class="muted" style="font-weight:400;font-size:14px">({{ number_format($results->total()) }})</span></h2>
        <table class="data">
            <thead>
                <tr>
                    <th>Reference</th>
                    <th>Polling unit</th>
                    @foreach ($parties as $party)<th class="num">{{ $party }}</th>@endforeach
                    <th class="num">Valid</th>
                    <th class="num">Rejected</th>
                    <th class="num">Accredited</th>
                    <th>Agent</th>
                    <th>Submitted</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($results as $result)
                    @php($votes = $result->votesByParty())
                    <tr>
                        <td><a href="{{ route('admin.results.show', $result) }}"><b>{{ $result->reference }}</b></a></td>
                        <td class="text">{{ $result->pollingUnit?->name ?? '—' }}<span class="sub">{{ $result->polling_unit_code }}@if ($result->pollingUnit) · {{ $result->pollingUnit->ward }}, {{ $result->pollingUnit->lga }}@endif</span></td>
                        @foreach ($parties as $party)<td class="num">{{ number_format($votes[$party] ?? 0) }}</td>@endforeach
                        <td class="num"><b>{{ number_format($result->total_valid_votes) }}</b></td>
                        <td class="num">{{ number_format($result->rejected_votes) }}</td>
                        <td class="num">{{ number_format($result->accredited_voters) }}@if ($turnout($result) !== null)<span class="sub">{{ $turnout($result) }}% turnout</span>@endif</td>
                        <td>{{ $result->agent->name }}<span class="sub">{{ $result->agent->phone_number }}</span></td>
                        <td>{{ $result->created_at->timezone(config('election.timezone'))->format('j M, g:i A') }}</td>
                        <td><span class="badge {{ $result->status->value }}">{{ ucfirst($result->status->value) }}</span>@if ($result->isCorrection())<span class="sub">correction</span>@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="{{ 8 + count($parties) }}" class="empty">No results match these filters.</td></tr>
                @endforelse
            </tbody>
        </table>
        <div class="pagination">
            @if ($results->previousPageUrl())<a href="{{ $results->previousPageUrl() }}">← Previous</a>@endif
            <span class="muted">Page {{ $results->currentPage() }} of {{ $results->lastPage() }}</span>
            @if ($results->nextPageUrl())<a href="{{ $results->nextPageUrl() }}">Next →</a>@endif
        </div>
    </div>

    <script>
        // Ward list follows the chosen LGA.
        (function () {
            const wards = @json($wardsByLga);
            const lga = document.getElementById('lga');
            const ward = document.getElementById('ward');
            lga.addEventListener('change', function () {
                ward.innerHTML = '<option value="">All wards</option>';
                (wards[lga.value] || []).forEach(function (name) { ward.add(new Option(name, name)); });
                ward.disabled = !lga.value;
            });
        })();
    </script>
@endsection
