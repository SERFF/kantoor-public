@extends('layout', ['title' => 'Toegang geven – Kantoor'])

@section('content')
    <h1>{{ $client->name }} toegang geven?</h1>
    <p>{{ $client->name }} mag via deze connector namens {{ $user->email ?? auth()->user()->email }} de status van je agents in het kantoor bijwerken en bekijken.</p>

    @if (count($scopes) > 0)
        <ul>
            @foreach ($scopes as $scope)
                <li>{{ $scope->description }}</li>
            @endforeach
        </ul>
    @endif

    <form method="POST" action="{{ route('passport.authorizations.approve') }}">
        @csrf
        <input type="hidden" name="state" value="">
        <input type="hidden" name="client_id" value="{{ $client->id }}">
        <input type="hidden" name="auth_token" value="{{ $authToken }}">
        <button type="submit">Toestaan</button>
    </form>

    <form method="POST" action="{{ route('passport.authorizations.deny') }}">
        @csrf
        @method('DELETE')
        <input type="hidden" name="state" value="">
        <input type="hidden" name="client_id" value="{{ $client->id }}">
        <input type="hidden" name="auth_token" value="{{ $authToken }}">
        <button type="submit" class="link">Weigeren</button>
    </form>
@endsection
