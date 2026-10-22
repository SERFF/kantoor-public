<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Logbook · Kantoor</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Pixelify+Sans:wght@400;600;700&family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap">
    <link rel="stylesheet" href="{{ asset('css/office.css') }}?v={{ filemtime(public_path('css/office.css')) }}">
</head>
<body>
<div class="page log-page">
    <header class="top">
        <div class="title">
            <div class="eyebrow"><span>{{ config('kantoor.brand') ? mb_strtoupper(config('kantoor.brand')).' · ' : '' }}VIRTUAL OFFICE</span></div>
            <h1>Logbook</h1>
            <p>{{ $total }} {{ $total === 1 ? 'delivery' : 'deliveries' }}{{ $project ? ' for '.$project->name : '' }} in the past {{ strtolower($periods[$days]) }}.</p>
        </div>
        <div class="stats">
            <a class="ghost" href="{{ route('office') }}">Back to the office</a>
        </div>
    </header>

    <nav class="chips log-filters" aria-label="Filter">
        <a class="chip" href="{{ route('logbook', ['days' => $days]) }}" aria-current="{{ $project ? 'false' : 'true' }}">All projects</a>
        @foreach ($projects as $item)
            <a class="chip" href="{{ route('logbook', ['project' => $item->slug, 'days' => $days]) }}" aria-current="{{ $project?->is($item) ? 'true' : 'false' }}">{{ $item->name }}</a>
        @endforeach
        <span class="log-sep" aria-hidden="true"></span>
        @foreach ($periods as $value => $label)
            <a class="chip" href="{{ route('logbook', array_filter(['project' => $project?->slug, 'days' => $value])) }}" aria-current="{{ $value === $days ? 'true' : 'false' }}">{{ $label }}</a>
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
                            <strong>{{ $entry['message'] ?: 'Done' }}</strong>
                            <span>{{ $entry['agent'] }}{{ $entry['role'] ? ' · '.$entry['role'] : '' }}{{ ! $project && $entry['project'] ? ' · '.$entry['project'] : '' }}</span>
                        </span>
                    </li>
                @endforeach
            </ol>
        </section>
    @empty
        <section class="panel log-day"><p class="log-empty">Nothing delivered in this period yet.</p></section>
    @endforelse
</div>
</body>
</html>
