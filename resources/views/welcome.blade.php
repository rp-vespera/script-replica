<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'Home') }}</title>
    <style>
        :root { color-scheme: light dark; }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            min-height: 100vh;
            display: flex; align-items: center; justify-content: center;
            font: 16px/1.6 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            background: #f7f8fa;
            color: #1a1d27;
            padding: 2rem;
        }
        @media (prefers-color-scheme: dark) {
            body { background: #0f1117; color: #e6e8ee; }
            .muted { color: #8b92a4 !important; }
        }
        .card { text-align: center; max-width: 520px; }
        h1 { font-size: clamp(2rem, 6vw, 3.25rem); letter-spacing: -.02em; line-height: 1.1; }
        p.muted { margin-top: 1rem; font-size: 1.05rem; color: #5b6273; }
        .meta { margin-top: 2rem; font-size: .8rem; color: #9aa0ad; }
    </style>
</head>
<body>
    <div class="card">
        <h1>{{ config('app.name', 'Welcome') }}</h1>
        <p class="muted">Welcome. This site is up and running.</p>
        <div class="meta">{{ strtoupper(config('app.env')) }}</div>
    </div>
</body>
</html>
