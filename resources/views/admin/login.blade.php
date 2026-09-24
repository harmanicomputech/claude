@extends('admin.layout')

@section('title', 'Log in')

@section('content')
    <div class="card" style="max-width:380px;margin:60px auto">
        <h1>Election Shield Admin</h1>
        <form method="post" action="{{ route('admin.login.attempt') }}">
            @csrf
            <label for="password">Admin password</label>
            <input type="password" id="password" name="password" required autofocus>
            <p><button type="submit">Log in</button></p>
        </form>
    </div>
@endsection
