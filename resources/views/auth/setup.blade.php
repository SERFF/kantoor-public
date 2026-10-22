@extends('layout', ['title' => 'Set password – Kantoor'])

@section('content')
    <h1>Set your password</h1>
    <p>Choose a password for {{ $user->email }}. This link works only once.</p>

    <form method="POST" action="{{ $action }}">
        @csrf

        <label for="password">Password (at least 12 characters)</label>
        <input id="password" type="password" name="password" required autofocus autocomplete="new-password">
        @error('password')<div class="error">{{ $message }}</div>@enderror

        <label for="password_confirmation">Repeat password</label>
        <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password">

        <button type="submit">Save and log in</button>
    </form>
@endsection
