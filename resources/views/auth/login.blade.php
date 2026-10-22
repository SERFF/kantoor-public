@extends('layout', ['title' => 'Inloggen – Kantoor'])

@section('content')
    <h1>Kantoor</h1>
    <p>Log in om je kantoor te bekijken of Claude te koppelen.</p>

    <form method="POST" action="{{ url('/login') }}">
        @csrf

        <label for="email">E-mail</label>
        <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username">
        @error('email')<div class="error">{{ $message }}</div>@enderror

        <label for="password">Wachtwoord</label>
        <input id="password" type="password" name="password" required autocomplete="current-password">

        <label class="check"><input type="checkbox" name="remember" value="1"> Ingelogd blijven</label>

        <button type="submit">Inloggen</button>
    </form>
@endsection
