<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $title ?? 'Kantoor' }}</title>
    <style>
        :root { --bg: #f6f6f4; --card: #fff; --text: #1b1b18; --muted: #6b6b66; --border: #e3e3df; --accent: #7AA83A; --error: #c62828; }
        @media (prefers-color-scheme: dark) {
            :root { --bg: #111110; --card: #1b1b19; --text: #ededec; --muted: #9a9a95; --border: #2c2c29; --accent: #B8F25A; --error: #ef5350; }
        }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 16px; background: var(--bg); color: var(--text); font: 15px/1.5 ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif; }
        .card { width: 100%; max-width: 380px; background: var(--card); border: 1px solid var(--border); border-radius: 12px; padding: 28px; }
        h1 { font-size: 18px; margin: 0 0 4px; }
        p { margin: 0 0 20px; color: var(--muted); }
        label { display: block; font-size: 13px; font-weight: 600; margin: 14px 0 6px; }
        input[type=email], input[type=password] { width: 100%; padding: 10px 12px; border: 1px solid var(--border); border-radius: 8px; background: transparent; color: var(--text); font: inherit; }
        input:focus { outline: 2px solid var(--accent); outline-offset: 1px; }
        .check { display: flex; gap: 8px; align-items: center; font-weight: 400; margin-top: 14px; }
        button { width: 100%; margin-top: 20px; padding: 10px; border: 0; border-radius: 8px; background: var(--accent); color: #fff; font: inherit; font-weight: 600; cursor: pointer; }
        .link { background: none; color: var(--muted); margin-top: 8px; text-decoration: underline; }
        .error { color: var(--error); font-size: 13px; margin-top: 6px; }
        code { font-size: 13px; background: var(--bg); padding: 2px 6px; border-radius: 4px; }
    </style>
</head>
<body>
    <main class="card">
        @yield('content')
    </main>
</body>
</html>
