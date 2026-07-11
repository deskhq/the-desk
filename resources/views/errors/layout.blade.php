{{--
    Self-contained "The Desk" error shell for the 500/503 fallbacks.

    This renders without a booted Inertia app or a live session, and makes no
    external asset requests (system fonts, inline SVG mark, inline styles), so a
    hard failure still shows a branded page. Light + dark follow the OS setting.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') — {{ config('app.name', 'The Desk') }}</title>
    <style>
        :root {
            --canvas-from: #f0ece0;
            --canvas-to: #e7e4dd;
            --ink: #1d1a15;
            --muted: #665f4e;
            --brass: #c9a35c;
            --pill-bg: #1d1a15;
            --pill-fg: #f3efe4;
            --pill-outline: #d3cdba;
            --pill-outline-bg: #fbfaf7;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --canvas-from: #2a271f;
                --canvas-to: #12100c;
                --ink: #f3efe4;
                --muted: #a49a86;
                --brass: #c9a35c;
                --pill-bg: #f3efe4;
                --pill-fg: #1d1a15;
                --pill-outline: #2e2a21;
                --pill-outline-bg: #1e1b15;
            }
        }

        * { box-sizing: border-box; }

        html, body { margin: 0; padding: 0; height: 100%; }

        body {
            min-height: 100%;
            display: flex;
            flex-direction: column;
            background: radial-gradient(900px 400px at 50% -80px, var(--canvas-from), var(--canvas-to));
            color: var(--ink);
            font-family: system-ui, -apple-system, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            -webkit-font-smoothing: antialiased;
        }

        .masthead {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 24px 28px;
            font-family: ui-serif, Georgia, 'Times New Roman', serif;
            font-size: 18px;
            font-weight: 600;
            letter-spacing: -0.01em;
        }

        main {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 0 24px 80px;
        }

        .code {
            font-family: ui-serif, Georgia, 'Times New Roman', serif;
            font-size: 96px;
            line-height: 1;
            font-weight: 500;
            letter-spacing: -0.03em;
            color: var(--brass);
        }

        h1 {
            margin: 20px 0 0;
            max-width: 32rem;
            font-family: ui-serif, Georgia, 'Times New Roman', serif;
            font-size: 30px;
            font-weight: 600;
            letter-spacing: -0.015em;
            text-wrap: balance;
        }

        p {
            margin: 12px 0 0;
            max-width: 28rem;
            font-size: 15px;
            line-height: 1.6;
            color: var(--muted);
            text-wrap: pretty;
        }

        .actions {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 12px;
            margin-top: 32px;
        }

        .pill {
            display: inline-flex;
            align-items: center;
            height: 44px;
            padding: 0 26px;
            border-radius: 99px;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
        }

        .pill--primary {
            background: var(--pill-bg);
            color: var(--pill-fg);
            box-shadow: 0 2px 6px rgba(29, 26, 21, 0.2);
        }

        .pill--ghost {
            background: var(--pill-outline-bg);
            color: var(--ink);
            border: 1px solid var(--pill-outline);
            font-weight: 550;
        }
    </style>
</head>
<body>
    <div class="masthead">
        <svg width="22" height="22" viewBox="0 0 40 40" aria-hidden="true">
            <polygon points="20,18 36,27 20,36 4,27" fill="currentColor" opacity="0.4"></polygon>
            <polygon points="20,11 36,20 20,29 4,20" fill="currentColor" opacity="0.7"></polygon>
            <polygon points="20,4 36,13 20,22 4,13" fill="#c9a35c"></polygon>
        </svg>
        <span>{{ config('app.name', 'The Desk') }}</span>
    </div>

    <main>
        <span class="code" aria-hidden="true">@yield('code')</span>
        <h1>@yield('title')</h1>
        <p>@yield('message')</p>
        @hasSection('actions')
            <div class="actions">@yield('actions')</div>
        @endif
    </main>
</body>
</html>
