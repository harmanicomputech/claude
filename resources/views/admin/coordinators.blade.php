@extends('admin.layout')

@section('title', 'Coordinators')

@section('content')
    <h1>Coordinators</h1>
    <p class="muted">Coordinators receive an SMS the moment an urgent incident (violence) is reported in their LGA. State-wide coordinators receive alerts for every LGA.</p>

    @if (auth()->user()->isAdmin())
    <div class="card">
        <h2>Add or update a coordinator</h2>
        <form method="post" action="{{ route('admin.coordinators.store') }}" class="row">
            @csrf
            <div><label>Name</label><input type="text" name="name" required></div>
            <div><label>Phone</label><input type="text" name="phone" placeholder="08012345678" required></div>
            <div><label>Email (optional)</label><input type="email" name="email"></div>
            <div>
                <label>LGA</label>
                <select name="lga">
                    <option value="">All LGAs (state-wide)</option>
                    @foreach ($lgas as $lga)<option>{{ $lga }}</option>@endforeach
                </select>
            </div>
            <button type="submit">Save</button>
        </form>
    </div>
    @endif

    <div class="card">
        <table>
            <tr><th>Name</th><th>Phone</th><th>Email</th><th>Covers</th><th></th></tr>
            @forelse ($coordinators as $coordinator)
                <tr>
                    <td>{{ $coordinator->name }}</td>
                    <td>{{ $coordinator->phone_number }}</td>
                    <td>{{ $coordinator->email ?? '—' }}</td>
                    <td>{{ $coordinator->lga ?? 'All LGAs' }}</td>
                    <td>
                        @if (auth()->user()->isAdmin())
                        <form method="post" action="{{ route('admin.coordinators.destroy', $coordinator) }}" onsubmit="return confirm('Remove {{ $coordinator->name }}?')">
                            @csrf @method('DELETE')
                            <button type="submit" class="danger">Remove</button>
                        </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="muted">No coordinators yet.</td></tr>
            @endforelse
        </table>
    </div>
@endsection
