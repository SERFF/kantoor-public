@extends('layout', ['title' => 'Wachtwoord instellen – Kantoor'])

@section('content')
    <h1>Wachtwoord instellen</h1>
    <p>Kies een wachtwoord voor {{ $user->email }}. Deze link werkt één keer.</p>

    <form method="POST" action="{{ $action }}">
        @csrf

        <label for="password">Wachtwoord (minimaal 12 tekens)</label>
        <input id="password" type="password" name="password" required autofocus autocomplete="new-password">
        @error('password')<div class="error">{{ $message }}</div>@enderror

        <label for="password_confirmation">Herhaal wachtwoord</label>
        <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password">

        <button type="submit">Opslaan en inloggen</button>
    </form>
@endsection
