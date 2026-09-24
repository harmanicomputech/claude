@extends('admin.layout')

@section('title', 'Polling units')

@php
    $tz = config('election.timezone');
    $tabs = [
        'all' => ['All', $counts['all']],
        'reported' => ['Result received', $counts['reported']],
        'no_result' => ['No result yet', $counts['all'] - $counts['reported']],
        'checked_in' => ['Agent checked in', $counts['checked_in']],
        'no_presence' => ['No check-in', $counts['all'] - $counts['checked_in']],
        'materials_arrived' => ['Materials arrived', $counts['materials_arrived']],
        'materials_problem' => ['Materials not confirmed', $counts['all'] - $counts['materials_arrived']],
    ];
    $materialBadge = ['arrived' => 'accepted', 'incomplete' => 'pending', 'not_arrived' => 'rejected'];
@endphp

@section('content')
    <div class="page-head">
        <div>
            <h1>Polling units</h1>
            <p class="muted">The INEC register, with each unit's check-in and result status.</p>
        </div>
        <div class="actions">
            <a class="button" href="{{ route('admin.polling-units.export', request()->query()) }}">Export (CSV)</a>
            <button type="button" class="secondary" onclick="window.print()">Print / Save PDF</button>
        </div>
    </div>

    <div class="tabs">
        @foreach ($tabs as $value => [$label, $count])
            <a href="{{ route('admin.polling-units.index', [...request()->except('status', 'page'), 'status' => $value]) }}" @class(['on' => $filters['status'] === $value])>{{ $label }} <b>{{ number_format($count) }}</b></a>
        @endforeach
    </div>

    <div class="card no-print">
        <form method="get" class="filters">
            <input type="hidden" name="status" value="{{ $filters['status'] }}">
            <div class="wide"><label>Search</label><input type="search" name="q" value="{{ $filters['q'] }}" placeholder="PU name or code"></div>
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
            <div class="actions" style="flex:0 0 auto"><button type="submit">Apply</button> <a class="button secondary" href="{{ route('admin.polling-units.index') }}">Reset</a></div>
        </form>
    </div>

    <div class="card">
        <table class="data">
            <thead>
                <tr><th>Code</th><th>Polling unit</th><th class="num">Registered</th><th>Agent(s)</th><th>Checked in</th><th>Materials</th><th>Result</th></tr>
            </thead>
            <tbody>
                @forelse ($units as $unit)
                    @php($detail = $details[$unit->code])
                    <tr>
                        <td>{{ $unit->code }}</td>
                        <td class="text">{{ $unit->name }}<span class="sub">{{ $unit->ward }}, {{ $unit->lga }}</span></td>
                        <td class="num">{{ $unit->registered_voters !== null ? number_format($unit->registered_voters) : '—' }}</td>
                        <td class="text">
                            @forelse ($detail['agents'] as $agent)
                                {{ $agent->name }}<span class="sub">{{ $agent->phone_number }}</span>
                            @empty
                                <span class="muted">None assigned</span>
                            @endforelse
                        </td>
                        <td>
                            @if ($detail['checked_in_at'])
                                <span class="badge accepted">Yes</span><span class="sub">{{ $detail['checked_in_at']->timezone($tz)->format('j M, g:i A') }}</span>
                            @else
                                <span class="badge neutral">No</span>
                            @endif
                        </td>
                        <td>
                            @if ($detail['materials'])
                                <span class="badge {{ $materialBadge[$detail['materials']->status->value] }}">{{ $detail['materials']->status->label() }}</span><span class="sub">{{ $detail['materials']->reported_at->timezone($tz)->format('j M, g:i A') }}</span>
                            @else
                                <span class="badge neutral">No report</span>
                            @endif
                        </td>
                        <td>
                            @if ($detail['result'])
                                <a href="{{ route('admin.results.show', $detail['result']) }}"><b>{{ $detail['result']->reference }}</b></a>
                                <span class="sub">{{ number_format($detail['result']->total_valid_votes) }} valid votes</span>
                            @else
                                <span class="badge neutral">Not yet</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="empty">No polling units match these filters.</td></tr>
                @endforelse
            </tbody>
        </table>
        <div class="pagination">
            @if ($units->previousPageUrl())<a href="{{ $units->previousPageUrl() }}">← Previous</a>@endif
            <span class="muted">{{ number_format($units->total()) }} unit(s) · page {{ $units->currentPage() }} of {{ $units->lastPage() }}</span>
            @if ($units->nextPageUrl())<a href="{{ $units->nextPageUrl() }}">Next →</a>@endif
        </div>
    </div>

    <script>
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
