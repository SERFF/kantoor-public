@extends('layout', ['title' => 'Log in – Kantoor'])

@section('content')
    <h1>Kantoor</h1>
    <p>Log in to view your office or connect Claude.</p>

    <form method="POST" action="{{ url('/login') }}">
        @csrf

        <label for="email">Email</label>
        <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username">
        @error('email')<div class="error">{{ $message }}</div>@enderror

        <label for="password">Password</label>
        <input id="password" type="password" name="password" required autocomplete="current-password">

        <label class="check"><input type="checkbox" name="remember" value="1"> Remember me</label>

        <button type="submit">Log in</button>
    </form>
@endsection
