@extends('admin.layout')

@section('title', $mode === 'setup' ? 'Set up' : 'Log in')

@section('content')
    <div class="card" style="max-width:420px;margin:48px auto">
        <h1>Election Shield Admin</h1>

        @if ($mode === 'no-database')
            <p class="flash bad">The database can't be reached. Check the <code>DB_*</code> settings in <code>election-shield/.env</code>, then reload this page.</p>
        @elseif ($mode === 'setup')
            <p class="muted">Create the first admin account. You need the setup key: the <code>ADMIN_PASSWORD</code> value in <code>election-shield/.env</code>. This also brings the database up to date.</p>
            <form method="post" action="{{ route('admin.setup') }}">
                @csrf
                <label for="setup_key">Setup key (ADMIN_PASSWORD)</label>
                <input type="password" id="setup_key" name="setup_key" required autofocus>
                <label for="name">Your name</label>
                <input type="text" id="name" name="name" value="{{ old('name') }}" required>
                <label for="email">Email</label>
                <input type="email" id="email" name="email" value="{{ old('email') }}" required>
                <label for="password">New password (at least 10 characters)</label>
                <input type="password" id="password" name="password" required>
                <label for="password_confirmation">Repeat password</label>
                <input type="password" id="password_confirmation" name="password_confirmation" required>
                <p><button type="submit">Create admin account</button></p>
            </form>
        @else
            <form method="post" action="{{ route('admin.login.attempt') }}">
                @csrf
                <label for="email">Email</label>
                <input type="email" id="email" name="email" value="{{ old('email') }}" required autofocus>
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required>
                <p><label class="inline"><input type="checkbox" name="remember" value="1"> Keep me logged in on this device</label></p>
                <p><button type="submit">Log in</button></p>
            </form>
        @endif
    </div>
@endsection
