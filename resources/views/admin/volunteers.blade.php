@extends('admin.layout')

@section('title', 'Volunteers')

@php($tz = config('election.timezone'))

@section('content')
    <div class="page-head">
        <div>
            <h1>Volunteers</h1>
            <p class="muted">Signed up on USSD with “How can you help?” ({{ number_format($total) }} in all). One entry per phone number: signing up again updates it. The web app has the same list with call and WhatsApp buttons.</p>
        </div>
        <div class="actions">
            <a class="button" href="{{ route('admin.volunteers.export', request()->query()) }}">Export (CSV)</a>
        </div>
    </div>

    <div class="card no-print">
        <form method="get" class="filters">
            <div class="wide"><label>Search</label><input type="search" name="q" value="{{ $filters['q'] }}" placeholder="Name, phone number, ward or reference"></div>
            <div>
                <label>How they can help</label>
                <select name="role">
                    <option value="">Anything</option>
                    @foreach ($roles as $role)<option value="{{ $role->value }}" @selected($filters['role'] === $role->value)>{{ $role->label() }}</option>@endforeach
                </select>
            </div>
            <div>
                <label>LGA</label>
                <select name="lga">
                    <option value="">All LGAs</option>
                    @foreach ($lgas as $lga)<option @selected($filters['lga'] === $lga)>{{ $lga }}</option>@endforeach
                </select>
            </div>
            <div class="actions" style="flex:0 0 auto"><button type="submit">Apply</button> <a class="button secondary" href="{{ route('admin.volunteers.index') }}">Reset</a></div>
        </form>
    </div>

    <div class="card">
        <table class="data">
            <thead>
                <tr><th>Name</th><th>Where</th><th>How they can help</th><th>Signed up</th></tr>
            </thead>
            <tbody>
                @forelse ($volunteers as $volunteer)
                    <tr>
                        <td><b>{{ $volunteer->name }}</b><span class="sub">{{ $volunteer->contact_phone }}@if ($volunteer->is_agent) · agent @endif · {{ $volunteer->reference }}</span></td>
                        <td class="text">{{ $volunteer->ward }}<span class="sub">{{ $volunteer->lga }}</span></td>
                        <td class="text">{{ implode(', ', $volunteer->roleLabels()) }}@if ($volunteer->skills)<span class="sub">Skills: {{ implode(', ', $volunteer->skillLabels()) }}</span>@endif @if ($volunteer->other)<span class="sub">“{{ $volunteer->other }}”</span>@endif</td>
                        <td>{{ $volunteer->created_at->timezone($tz)->format('j M, g:i A') }}@if ($volunteer->updated_at->gt($volunteer->created_at->copy()->addMinute()))<span class="sub">updated {{ $volunteer->updated_at->diffForHumans() }}</span>@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="empty">No volunteers match these filters.</td></tr>
                @endforelse
            </tbody>
        </table>
        <div class="pagination">
            @if ($volunteers->previousPageUrl())<a href="{{ $volunteers->previousPageUrl() }}">← Previous</a>@endif
            <span class="muted">{{ number_format($volunteers->total()) }} volunteer(s) · page {{ $volunteers->currentPage() }} of {{ $volunteers->lastPage() }}</span>
            @if ($volunteers->nextPageUrl())<a href="{{ $volunteers->nextPageUrl() }}">Next →</a>@endif
        </div>
    </div>
@endsection
