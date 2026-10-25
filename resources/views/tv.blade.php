<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#101217">
    <title>Kantoor · TV</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Pixelify+Sans:wght@400;600;700&family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap">
    <link rel="stylesheet" href="{{ asset('css/office.css') }}?v={{ filemtime(public_path('css/office.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/tv.css') }}?v={{ filemtime(public_path('css/tv.css')) }}">
</head>
<body class="tv">
<div class="tv-page">
    <header class="tv-top">
        <div class="tv-brand">
            <div class="eyebrow">
                <span>{{ mb_strtoupper(config('kantoor.brand') ?: 'Virtual office') }}</span>
                <span class="live" id="live" data-state="connecting">connecting…</span>
            </div>
            <h1>The office</h1>
        </div>
        <p class="tv-summary" id="summary">Checking who is in…</p>
        <div class="stats">
            <div class="stat"><strong id="count-working">0</strong><span>working</span></div>
            <div class="stat wait" id="stat-waiting"><strong id="count-waiting">0</strong><span>waiting for you</span></div>
            <div class="stat err"><strong id="count-error">0</strong><span>stuck</span></div>
        </div>
        <div class="tv-clock">
            <time id="tv-clock">--:--</time>
            <span id="tv-date"></span>
        </div>
    </header>

    <nav class="chips" id="chips" aria-hidden="true"></nav>

    <main class="tv-main">
        <div class="stage" id="stage">
            <canvas id="office" role="img" aria-label="Isometric office with the agents per project"></canvas>
            <div class="empty" id="empty" hidden>
                <h2>Nobody in the office yet</h2>
                <p>As soon as an agent reports in, it takes a seat here.</p>
            </div>
            <section class="pop" id="detail" hidden></section>
        </div>
        <aside class="tv-side">
            <section class="alarms" id="alarms" hidden aria-live="polite"></section>
            <section class="tv-wait" id="tv-wait" hidden aria-live="polite"></section>
            <section class="roster" id="roster">
                <div class="panel-head">
                    <h2 id="list-title">All agents</h2>
                    <span class="mono" id="list-count"></span>
                </div>
                <div class="cards" id="cards"></div>
            </section>
            <section class="panel tv-week" id="tv-week" hidden>
                <div class="panel-head">
                    <h2 id="week-title">Last week</h2>
                    <span class="mono">weekly recap</span>
                </div>
                <div class="week-body" id="week"></div>
            </section>
            <section class="panel tv-feed">
                <div class="panel-head">
                    <h2>Collaboration</h2>
                    <span class="mono">today</span>
                </div>
                <ol class="feed" id="feed"></ol>
            </section>
        </aside>
    </main>
    <button type="button" class="tv-fullscreen" id="tv-fullscreen" hidden>Press OK for full screen</button>
</div>

<script>
    window.KANTOOR = @json($config);
</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pusher/8.4.0/pusher.min.js"></script>
<script src="{{ asset('js/office.js') }}?v={{ filemtime(public_path('js/office.js')) }}"></script>
</body>
</html>
