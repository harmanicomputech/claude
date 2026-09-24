@extends('admin.layout')

@section('title', 'Users')

@section('content')
    <div class="page-head">
        <div>
            <h1>Users</h1>
            <p class="muted"><b>Admins</b> can do everything. <b>Coordinators</b> can see all data, export it and review corrections, but can't change agents, users or settings.</p>
        </div>
    </div>

    <div class="card">
        <h2>Add a user</h2>
        <form method="post" action="{{ route('admin.users.store') }}" class="filters">
            @csrf
            <div><label>Name</label><input type="text" name="name" value="{{ old('name') }}" required></div>
            <div><label>Email</label><input type="email" name="email" value="{{ old('email') }}" required></div>
            <div>
                <label>Role</label>
                <select name="role">
                    @foreach ($roles as $role)<option value="{{ $role->value }}" @selected(old('role', 'coordinator') === $role->value)>{{ $role->label() }}</option>@endforeach
                </select>
            </div>
            <div class="actions" style="flex:0 0 auto"><button type="submit">Create account</button></div>
        </form>
        <p class="muted">A temporary password is shown once after creating the account. Share it privately; they can change it on the Overview page.</p>
    </div>

    <div class="card">
        <table class="data">
            <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Last login</th><th></th></tr></thead>
            <tbody>
                @foreach ($users as $user)
                    <tr>
                        <td>{{ $user->name }}@if ($user->is(auth()->user())) <span class="badge neutral">You</span>@endif</td>
                        <td>{{ $user->email }}</td>
                        <td>
                            <form method="post" action="{{ route('admin.users.update', $user) }}" class="row" style="gap:6px">
                                @csrf @method('PATCH')
                                <select name="role" style="min-width:130px">
                                    @foreach ($roles as $role)<option value="{{ $role->value }}" @selected($user->role === $role)>{{ $role->label() }}</option>@endforeach
                                </select>
                                <button type="submit" class="secondary">Save</button>
                            </form>
                        </td>
                        <td>{{ $user->last_login_at?->timezone(config('election.timezone'))->format('j M, g:i A') ?? 'Never' }}</td>
                        <td>
                            <div class="actions">
                                <form method="post" action="{{ route('admin.users.password', $user) }}" onsubmit="return confirm('Give {{ $user->name }} a new temporary password?')">@csrf<button type="submit" class="secondary">Reset password</button></form>
                                @unless ($user->is(auth()->user()))
                                    <form method="post" action="{{ route('admin.users.destroy', $user) }}" onsubmit="return confirm('Remove {{ $user->name }}?')">@csrf @method('DELETE')<button type="submit" class="danger">Remove</button></form>
                                @endunless
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endsection
