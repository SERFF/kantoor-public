<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Kantoor</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Pixelify+Sans:wght@400;600;700&family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap">
    <link rel="stylesheet" href="{{ asset('css/office.css') }}?v={{ filemtime(public_path('css/office.css')) }}">
</head>
<body>
<div class="page">
    <header class="top">
        <div class="title">
            <div class="eyebrow">
                <span>{{ config('kantoor.brand') ? mb_strtoupper(config('kantoor.brand')).' · ' : '' }}VIRTUEEL KANTOOR</span>
                <span class="live" id="live" data-state="connecting">verbinden…</span>
            </div>
            <h1>Het kantoor</h1>
            <p id="summary">Even kijken wie er is…</p>
        </div>
        <div class="stats">
            <div class="stat"><strong id="count-working">0</strong><span>aan het werk</span></div>
            <button type="button" class="stat wait" id="stat-waiting" aria-label="Open de eerste agent die op je wacht"><strong id="count-waiting">0</strong><span>wacht op jou</span></button>
            <div class="stat err"><strong id="count-error">0</strong><span>storing</span></div>
            <a class="ghost" href="{{ route('logbook') }}">Logboek</a>
            <a class="ghost tv-open" href="{{ route('tv') }}" target="_blank" rel="noopener">Tv-weergave</a>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="ghost">Uitloggen</button>
            </form>
        </div>
    </header>

    <section class="alarms" id="alarms" hidden aria-live="polite"></section>

    <nav class="chips" id="chips" aria-label="Filter op project"></nav>

    <div class="workspace">
        <div class="stage" id="stage">
            <canvas id="office" role="img" aria-label="Isometrisch kantoor met de agents per project"></canvas>
            <div class="empty" id="empty" hidden>
                <h2>Nog niemand op kantoor</h2>
                <p>Koppel de connector in Claude of zet de Claude Code-hooks aan. Wil je eerst kijken hoe het eruitziet? Draai <code>php artisan kantoor:demo</code>.</p>
            </div>
            <section class="pop" id="detail" role="dialog" aria-modal="false" aria-labelledby="pop-name" hidden></section>
        </div>
        <section class="roster" id="roster">
            <div class="panel-head">
                <h2 id="list-title">Alle agents</h2>
                <span class="mono" id="list-count"></span>
            </div>
            <div class="cards" id="cards"></div>
        </section>
    </div>

    <div class="columns">
        <section class="panel">
            <div class="panel-head">
                <h2>Samenwerking</h2>
                <span class="mono">vandaag</span>
            </div>
            <ol class="feed" id="feed"></ol>
        </section>
        <section class="panel today">
            <div class="panel-head">
                <h2>Vandaag opgeleverd</h2>
                <a class="mono today-link" href="{{ route('logbook', ['dagen' => 7]) }}">logboek →</a>
            </div>
            <div class="today-body" id="today"></div>
        </section>
        <section class="panel connect">
            <h2>Koppelen</h2>
            <p>Connector-URL voor Claude: <code>{{ url('/mcp') }}</code></p>
            <p>Claude Code-hooks: <code>php artisan kantoor:hook-token "Naam van je Mac"</code></p>
        </section>
    </div>
</div>

<script>
    window.KANTOOR = @json($config);
</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pusher/8.4.0/pusher.min.js"></script>
<script src="{{ asset('js/office.js') }}?v={{ filemtime(public_path('js/office.js')) }}"></script>
</body>
</html>
