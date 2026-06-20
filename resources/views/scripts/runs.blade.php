<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Script Runs</title>
    <style>
        :root { color-scheme: dark; }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 2rem; font: 14px/1.5 system-ui, -apple-system, Segoe UI, Roboto, sans-serif; background: #0f1117; color: #e6e8ee; }
        h1 { font-size: 1.4rem; margin: 0 0 1rem; }
        .stats { display: flex; gap: 1rem; margin-bottom: 1.5rem; flex-wrap: wrap; }
        .card { background: #1a1d27; border: 1px solid #2a2e3a; border-radius: 10px; padding: 0.9rem 1.2rem; min-width: 120px; }
        .card .n { font-size: 1.8rem; font-weight: 700; }
        .card .l { font-size: 0.72rem; text-transform: uppercase; letter-spacing: .05em; opacity: .6; }
        .card.ok .n { color: #34d399; }
        .card.bad .n { color: #f87171; }
        table { width: 100%; border-collapse: collapse; background: #1a1d27; border: 1px solid #2a2e3a; border-radius: 10px; overflow: hidden; }
        th, td { text-align: left; padding: 0.6rem 0.9rem; border-bottom: 1px solid #2a2e3a; vertical-align: top; }
        th { font-size: 0.72rem; text-transform: uppercase; letter-spacing: .05em; opacity: .6; }
        tr:last-child td { border-bottom: none; }
        code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
        .badge { display: inline-block; padding: 0.15rem 0.55rem; border-radius: 999px; font-size: 0.72rem; font-weight: 600; white-space: nowrap; }
        .badge.success { background: rgba(52,211,153,.15); color: #34d399; }
        .badge.failed  { background: rgba(248,113,113,.15); color: #f87171; }
        .badge.pending { background: rgba(251,191,36,.15); color: #fbbf24; }
        .badge.approved{ background: rgba(96,165,250,.15); color: #60a5fa; }
        .badge.rejected{ background: rgba(148,163,184,.15); color: #94a3b8; }
        details summary { cursor: pointer; font-size: 0.78rem; opacity: .7; }
        pre { white-space: pre-wrap; word-break: break-word; background: #0f1117; border: 1px solid #2a2e3a; border-radius: 6px; padding: 0.6rem; margin: 0.4rem 0 0; max-height: 320px; overflow: auto; font-size: 0.78rem; }
        .muted { opacity: .55; }
        .empty { padding: 3rem; text-align: center; opacity: .6; }
    </style>
</head>
<body>
    <h1>Script Runs</h1>

    <div class="stats">
        <div class="card"><div class="n">{{ $stats['total'] }}</div><div class="l">Total</div></div>
        <div class="card ok"><div class="n">{{ $stats['success'] }}</div><div class="l">Succeeded</div></div>
        <div class="card bad"><div class="n">{{ $stats['failed'] }}</div><div class="l">Failed</div></div>
    </div>

    @if ($runs->isEmpty())
        <div class="card empty">No script runs recorded yet.</div>
    @else
        <table>
            <thead>
                <tr><th>Result</th><th>Script</th><th>Review</th><th>When</th><th>Duration</th><th>Details</th></tr>
            </thead>
            <tbody>
                @foreach ($runs as $run)
                    <tr>
                        <td><span class="badge {{ $run->status }}">{{ $run->status }}</span></td>
                        <td>
                            <code>{{ $run->filename }}</code>
                            @if ($run->moved_to)
                                <div class="muted" style="font-size:.72rem">→ scripts/{{ $run->moved_to }}/</div>
                            @endif
                        </td>
                        <td><span class="badge {{ $run->approval_status }}">{{ $run->approval_status }}</span></td>
                        <td title="{{ $run->ran_at }}">{{ $run->ran_at?->diffForHumans() }}</td>
                        <td>{{ $run->duration_ms !== null ? $run->duration_ms.' ms' : '—' }}</td>
                        <td>
                            @if ($run->error)
                                <details><summary>Error</summary><pre>{{ $run->error }}</pre></details>
                            @endif
                            @if ($run->output)
                                <details><summary>Output</summary><pre>{{ $run->output }}</pre></details>
                            @endif
                            @if (! $run->error && ! $run->output)
                                <span class="muted">—</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <p class="muted" style="margin-top:1rem">Read-only. Approvals happen on the protected review page.</p>
    @endif
</body>
</html>
