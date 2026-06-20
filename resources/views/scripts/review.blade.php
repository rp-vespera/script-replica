<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Script Review</title>
    <style>
        :root { color-scheme: dark; }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 2rem; font: 14px/1.5 system-ui, -apple-system, Segoe UI, Roboto, sans-serif; background: #0f1117; color: #e6e8ee; }
        h1 { font-size: 1.4rem; margin: 0 0 .3rem; }
        .lock { font-size: .75rem; color: #60a5fa; margin-bottom: 1.2rem; }
        .flash { background: rgba(52,211,153,.12); border: 1px solid rgba(52,211,153,.4); color: #34d399; padding: .7rem 1rem; border-radius: 8px; margin-bottom: 1.2rem; font-size: .9rem; }
        table { width: 100%; border-collapse: collapse; background: #1a1d27; border: 1px solid #2a2e3a; border-radius: 10px; overflow: hidden; }
        th, td { text-align: left; padding: 0.7rem 0.9rem; border-bottom: 1px solid #2a2e3a; }
        th { font-size: 0.72rem; text-transform: uppercase; letter-spacing: .05em; opacity: .6; }
        tr:last-child td { border-bottom: none; }
        code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
        .muted { opacity: .55; }
        .empty { padding: 3rem; text-align: center; opacity: .6; background: #1a1d27; border: 1px solid #2a2e3a; border-radius: 10px; }
        form.inline { display: inline; }
        button { font: inherit; font-size: .8rem; font-weight: 600; cursor: pointer; border: 1px solid #2a2e3a; border-radius: 7px; padding: .4rem .8rem; margin-right: .4rem; }
        button.approve { background: #34d399; color: #0f1117; border-color: #34d399; }
        button.reject  { background: transparent; color: #f87171; border-color: rgba(248,113,113,.4); }
        a { color: #60a5fa; }
    </style>
</head>
<body>
    <h1>Script Review</h1>
    <div class="lock">🔒 Authenticated area — runs scripts against the live database.</div>

    @if (session('status'))
        <div class="flash">{{ session('status') }}</div>
    @endif

    <h2 style="font-size:.95rem;text-transform:uppercase;letter-spacing:.05em;opacity:.6;margin:.5rem 0 .8rem">Pending</h2>
    @if ($pending->isEmpty())
        <div class="empty">Nothing waiting. New scripts placed in <code>scripts/pending/</code> appear here for approval.</div>
    @else
        <table>
            <thead>
                <tr><th>Script</th><th>Actions</th></tr>
            </thead>
            <tbody>
                @foreach ($pending as $name)
                    <tr>
                        <td><code>{{ $name }}</code></td>
                        <td>
                            <form class="inline" method="POST" action="{{ route('scripts.review.approve') }}">
                                @csrf
                                <input type="hidden" name="file" value="{{ $name }}">
                                <button class="approve" type="submit">Approve &amp; run</button>
                            </form>
                            <form class="inline" method="POST" action="{{ route('scripts.review.reject') }}"
                                  onsubmit="return confirm('Reject {{ $name }} without running?');">
                                @csrf
                                <input type="hidden" name="file" value="{{ $name }}">
                                <button class="reject" type="submit">Reject</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <h2 style="font-size:.95rem;text-transform:uppercase;letter-spacing:.05em;opacity:.6;margin:2rem 0 .8rem">Failed — fix &amp; retry</h2>
    @if ($failed->isEmpty())
        <div class="empty">No failed scripts.</div>
    @else
        <table>
            <thead>
                <tr><th>Script</th><th>Actions</th></tr>
            </thead>
            <tbody>
                @foreach ($failed as $name)
                    <tr>
                        <td><code>{{ $name }}</code> <span class="muted" style="font-size:.72rem">(scripts/failed/)</span></td>
                        <td>
                            <form class="inline" method="POST" action="{{ route('scripts.review.approve') }}">
                                @csrf
                                <input type="hidden" name="file" value="{{ $name }}">
                                <button class="approve" type="submit">Approve &amp; re-run</button>
                            </form>
                            <form class="inline" method="POST" action="{{ route('scripts.review.delete') }}"
                                  onsubmit="return confirm('Permanently delete {{ $name }} from scripts/failed/?');">
                                @csrf
                                <input type="hidden" name="file" value="{{ $name }}">
                                <button class="reject" type="submit">Delete</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <p class="muted" style="margin-top:1rem">
        <strong>Approve &amp; run</strong> executes the script now, records the result, and files it to
        <code>scripts/done/</code> (success) or <code>scripts/failed/</code> (failure).
        A failed script stays under <strong>Failed</strong> — fix it and <strong>Approve &amp; re-run</strong>;
        on success it moves to <code>scripts/done/</code>.
        See all results in the <a href="{{ route('scripts.runs') }}">run history</a>.
    </p>
</body>
</html>
