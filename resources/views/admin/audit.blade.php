@extends('admin.layout')

@section('title', 'Audit log')

@php($tz = config('election.timezone'))

@section('content')
    <div class="page-head">
        <div>
            <h1>Audit log</h1>
            <p class="muted">Every sensitive action in the console, plus agent lockouts. Entries cannot be edited or deleted from here.</p>
        </div>
        <div class="actions">
            <a class="button" href="{{ route('admin.audit.export', request()->query()) }}">Export (CSV)</a>
            <button type="button" class="secondary" onclick="window.print()">Print / Save PDF</button>
        </div>
    </div>

    <div class="card no-print">
        <form method="get" class="filters">
            <div class="wide"><label>Search</label><input type="search" name="q" value="{{ $filters['q'] }}" placeholder="Who, description or reference"></div>
            <div>
                <label>Action</label>
                <select name="action">
                    <option value="">All actions</option>
                    @foreach ($actions as $action)<option @selected($filters['action'] === $action)>{{ $action }}</option>@endforeach
                </select>
            </div>
            <div class="actions" style="flex:0 0 auto"><button type="submit">Apply</button> <a class="button secondary" href="{{ route('admin.audit.index') }}">Reset</a></div>
        </form>
    </div>

    <div class="card">
        <table class="data">
            <thead><tr><th>When</th><th>Who</th><th>Action</th><th>What happened</th><th>IP</th></tr></thead>
            <tbody>
                @forelse ($entries as $entry)
                    <tr>
                        <td>{{ $entry->created_at->timezone($tz)->format('j M, g:i:s A') }}</td>
                        <td>{{ $entry->user_name }}</td>
                        <td><span class="badge neutral">{{ $entry->action }}</span></td>
                        <td class="text">{{ $entry->description }}
                            @if ($entry->details)
                                <details><summary>Details</summary><pre style="margin:6px 0 0">{{ json_encode($entry->details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre></details>
                            @endif
                        </td>
                        <td class="muted">{{ $entry->ip_address ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="empty">Nothing recorded yet.</td></tr>
                @endforelse
            </tbody>
        </table>
        <div class="pagination">
            @if ($entries->previousPageUrl())<a href="{{ $entries->previousPageUrl() }}">← Newer</a>@endif
            <span class="muted">{{ number_format($entries->total()) }} entr{{ $entries->total() === 1 ? 'y' : 'ies' }}</span>
            @if ($entries->nextPageUrl())<a href="{{ $entries->nextPageUrl() }}">Older →</a>@endif
        </div>
    </div>
@endsection
