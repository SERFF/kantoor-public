@extends('layout', ['title' => 'Grant access – Kantoor'])

@section('content')
    <h1>Give {{ $client->name }} access?</h1>
    <p>Through this connector, {{ $client->name }} may view and update the status of your agents in the office on behalf of {{ $user->email ?? auth()->user()->email }}.</p>

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
        <button type="submit">Allow</button>
    </form>

    <form method="POST" action="{{ route('passport.authorizations.deny') }}">
        @csrf
        @method('DELETE')
        <input type="hidden" name="state" value="">
        <input type="hidden" name="client_id" value="{{ $client->id }}">
        <input type="hidden" name="auth_token" value="{{ $authToken }}">
        <button type="submit" class="link">Deny</button>
    </form>
@endsection
