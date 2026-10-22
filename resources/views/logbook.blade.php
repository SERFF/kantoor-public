<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Logboek · Kantoor</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Pixelify+Sans:wght@400;600;700&family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap">
    <link rel="stylesheet" href="{{ asset('css/office.css') }}?v={{ filemtime(public_path('css/office.css')) }}">
</head>
<body>
<div class="page log-page">
    <header class="top">
        <div class="title">
            <div class="eyebrow"><span>{{ config('kantoor.brand') ? mb_strtoupper(config('kantoor.brand')).' · ' : '' }}VIRTUEEL KANTOOR</span></div>
            <h1>Logboek</h1>
            <p>{{ $total }} {{ $total === 1 ? 'oplevering' : 'opleveringen' }}{{ $project ? ' voor '.$project->name : '' }} in de afgelopen {{ strtolower($periods[$days]) }}.</p>
        </div>
        <div class="stats">
            <a class="ghost" href="{{ route('office') }}">Terug naar kantoor</a>
        </div>
    </header>

    <nav class="chips log-filters" aria-label="Filter">
        <a class="chip" href="{{ route('logbook', ['dagen' => $days]) }}" aria-current="{{ $project ? 'false' : 'true' }}">Alle projecten</a>
        @foreach ($projects as $item)
            <a class="chip" href="{{ route('logbook', ['project' => $item->slug, 'dagen' => $days]) }}" aria-current="{{ $project?->is($item) ? 'true' : 'false' }}">{{ $item->name }}</a>
        @endforeach
        <span class="log-sep" aria-hidden="true"></span>
        @foreach ($periods as $value => $label)
            <a class="chip" href="{{ route('logbook', array_filter(['project' => $project?->slug, 'dagen' => $value])) }}" aria-current="{{ $value === $days ? 'true' : 'false' }}">{{ $label }}</a>
        @endforeach
    </nav>

    @forelse ($groups as $group)
        <section class="panel log-day">
            <div class="panel-head">
                <h2>{{ $group['label'] }}</h2>
                <span class="mono">{{ count($group['entries']) }}</span>
            </div>
            <ol class="log-list">
                @foreach ($group['entries'] as $entry)
                    <li>
                        <time>{{ $entry['time'] }}</time>
                        <span class="portrait" aria-hidden="true" style="background-image:url('{{ $portraits }}');background-size:{{ 32 * 6 }}px auto;background-position:-{{ ($entry['avatar'] % 6) * 32 }}px -{{ intdiv($entry['avatar'], 6) * 32 }}px;width:32px;height:32px"></span>
                        <span class="log-body">
                            <strong>{{ $entry['message'] ?: 'Klaar' }}</strong>
                            <span>{{ $entry['agent'] }}{{ $entry['role'] ? ' · '.$entry['role'] : '' }}{{ ! $project && $entry['project'] ? ' · '.$entry['project'] : '' }}</span>
                        </span>
                    </li>
                @endforeach
            </ol>
        </section>
    @empty
        <section class="panel log-day"><p class="log-empty">Nog niets opgeleverd in deze periode.</p></section>
    @endforelse
</div>
</body>
</html>
