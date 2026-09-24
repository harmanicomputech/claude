@extends('admin.layout')

@section('title', 'Missing PUs')

@section('content')
    <h1>Missing polling units</h1>

    <div class="card">
        <form method="get" class="row">
            <div>
                <label>Missing</label>
                <select name="type">
                    <option value="results" @selected($type === 'results')>Results (no accepted result)</option>
                    <option value="presence" @selected($type === 'presence')>Presence (no check-in today)</option>
                </select>
            </div>
            <div>
                <label>LGA</label>
                <select name="lga">
                    <option value="">All LGAs</option>
                    @foreach ($lgas as $option)<option @selected($lga === $option)>{{ $option }}</option>@endforeach
                </select>
            </div>
            <button type="submit" class="secondary">Show</button>
        </form>
    </div>

    <div class="card">
        <h2>{{ number_format($missing->count()) }} polling unit(s)</h2>
        <table>
            <tr><th>LGA</th><th>Ward</th><th>Code</th><th>Polling unit</th><th>Agent(s)</th></tr>
            @foreach ($missing->take(1000) as $unit)
                <tr>
                    <td>{{ $unit['lga'] }}</td>
                    <td>{{ $unit['ward'] }}</td>
                    <td>{{ $unit['code'] }}</td>
                    <td>{{ $unit['name'] }}</td>
                    <td>{{ collect($unit['agents'])->map(fn ($agent) => "{$agent['name']} {$agent['phone_number']}")->implode(', ') ?: '—' }}</td>
                </tr>
            @endforeach
        </table>
        @if ($missing->count() > 1000)<p class="muted">Showing the first 1,000. Filter by LGA to see the rest.</p>@endif
    </div>
@endsection
