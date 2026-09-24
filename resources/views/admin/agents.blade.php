@extends('admin.layout')

@section('title', 'Agents')

@section('content')
    <h1>Agents</h1>

    @if (session('import'))
        @php($import = session('import'))
        <div class="card">
            <h2>Import result</h2>
            @foreach ($import['errors'] as $error)
                <div class="flash bad" style="margin-bottom:6px">{{ $error }}</div>
            @endforeach
            @if ($import['imported'])
                <p class="muted">New PINs are shown <b>only now</b>. Download or copy them before leaving this page.</p>
                @php($csv = "name,phone,pu_code,pin\n".collect($import['imported'])->map(fn ($a) => '"'.str_replace('"', '""', $a['name']).'",'.$a['phone_number'].','.($a['polling_unit_code'] ?? '').','.($a['pin'] ?? ''))->implode("\n"))
                <p><a class="button secondary" download="agent-pins.csv" href="data:text/csv;base64,{{ base64_encode($csv) }}">Download PINs (CSV)</a></p>
                <table>
                    <tr><th>Name</th><th>Phone</th><th>PU</th><th>New PIN</th></tr>
                    @foreach ($import['imported'] as $row)
                        <tr><td>{{ $row['name'] }}</td><td>{{ $row['phone_number'] }}</td><td>{{ $row['polling_unit_code'] ?? '—' }}</td><td>{{ $row['pin'] ?? '(unchanged)' }}</td></tr>
                    @endforeach
                </table>
            @endif
        </div>
    @endif

    <div class="grid">
        <div class="card">
            <h2>Add or update one agent</h2>
            <form method="post" action="{{ route('admin.agents.store') }}">
                @csrf
                <label>Full name</label><input type="text" name="name" value="{{ old('name') }}" required>
                <label>Phone number</label><input type="text" name="phone" value="{{ old('phone') }}" placeholder="08012345678" required>
                <label>Assigned PU code (optional)</label><input type="text" name="pu_code" value="{{ old('pu_code') }}" placeholder="EB/212/02633/007 or 21202633007">
                <label>PIN (optional, 4 digits: random if blank)</label><input type="text" name="pin" inputmode="numeric" maxlength="4">
                <p><label class="inline"><input type="checkbox" name="sms_pin" value="1"> Text the PIN to the agent</label></p>
                <button type="submit">Save agent</button>
            </form>
        </div>

        <div class="card">
            <h2>Import agents from CSV</h2>
            <form method="post" action="{{ route('admin.agents.import') }}" enctype="multipart/form-data">
                @csrf
                <p class="muted">Header row: <code>name,phone,pu_code,pin</code>. <code>pu_code</code> and <code>pin</code> are optional. Agents without a PIN get a random one. Existing agents (same phone) are updated and keep their PIN unless one is given.</p>
                <input type="file" name="file" accept=".csv,text/csv" required>
                <p><label class="inline"><input type="checkbox" name="sms_pins" value="1"> Text each new PIN to its agent</label></p>
                <button type="submit">Import</button>
            </form>
        </div>
    </div>

    <div class="card" style="margin-top:20px">
        <form method="get" class="row" style="margin-bottom:12px">
            <input type="search" name="q" value="{{ $search }}" placeholder="Search name, phone or PU code">
            <button type="submit" class="secondary">Search</button>
        </form>
        <p class="muted">{{ number_format($agents->total()) }} agent(s)</p>
        <table>
            <tr><th>Name</th><th>Phone</th><th>Assigned PU</th><th>Status</th><th>PIN</th><th></th></tr>
            @forelse ($agents as $agent)
                <tr>
                    <td>{{ $agent->name }}</td>
                    <td>{{ $agent->phone_number }}</td>
                    <td>{{ $agent->polling_unit_code ?? '—' }}@if ($agent->pollingUnit)<br><span class="muted">{{ $agent->pollingUnit->name }}, {{ $agent->pollingUnit->lga }}</span>@endif</td>
                    <td>
                        @if ($agent->isLocked()) <span class="badge bad">Locked</span>
                        @elseif ($agent->is_active) <span class="badge ok">Checked in</span>
                        @endif
                        @if ($agent->last_seen_at)<br><span class="muted">{{ $agent->last_seen_at->diffForHumans() }}</span>@endif
                    </td>
                    <td>
                        <details>
                            <summary>Reset</summary>
                            <form method="post" action="{{ route('admin.agents.pin', $agent) }}">
                                @csrf
                                <input type="text" name="pin" inputmode="numeric" maxlength="4" placeholder="Random if blank">
                                <label class="inline"><input type="checkbox" name="sms_pin" value="1"> Text it</label>
                                <button type="submit" class="secondary">Set PIN &amp; unlock</button>
                            </form>
                        </details>
                    </td>
                    <td>
                        <form method="post" action="{{ route('admin.agents.destroy', $agent) }}" onsubmit="return confirm('Remove {{ $agent->name }}?')">
                            @csrf @method('DELETE')
                            <button type="submit" class="danger">Remove</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="muted">No agents yet.</td></tr>
            @endforelse
        </table>
        <div class="pagination">
            @if ($agents->previousPageUrl())<a href="{{ $agents->previousPageUrl() }}">← Previous</a>@endif
            <span class="muted">Page {{ $agents->currentPage() }} of {{ $agents->lastPage() }}</span>
            @if ($agents->nextPageUrl())<a href="{{ $agents->nextPageUrl() }}">Next →</a>@endif
        </div>
    </div>
@endsection
