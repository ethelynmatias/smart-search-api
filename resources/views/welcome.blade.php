<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ config('app.name', 'Smart Search API') }}</title>

        <style>
            *,
            *::before,
            *::after {
                box-sizing: border-box;
                margin: 0;
                padding: 0;
            }

            body {
                font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
                background: #0b1120;
                color: #e2e8f0;
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
            }

            .card {
                text-align: center;
                padding: 3rem 2rem;
            }

            .badge {
                display: inline-block;
                font-size: 0.75rem;
                font-weight: 600;
                letter-spacing: 0.1em;
                text-transform: uppercase;
                color: #ff7a59;
                border: 1px solid #ff7a59;
                border-radius: 9999px;
                padding: 0.35rem 1rem;
                margin-bottom: 1.5rem;
            }

            h1 {
                font-size: 2.5rem;
                font-weight: 700;
                margin-bottom: 0.75rem;
            }

            p {
                color: #94a3b8;
                font-size: 1.05rem;
            }

            .meta {
                margin-top: 2rem;
                font-size: 0.85rem;
                color: #64748b;
            }
        </style>
    </head>
    <body>
        <main class="card">
            <span class="badge">HubSpot</span>
            <h1>Smart Search API</h1>
            <p>Smart Search API for HubSpot.</p>
            <div class="meta">
                Laravel v{{ Illuminate\Foundation\Application::VERSION }} &middot; PHP v{{ PHP_VERSION }}
            </div>
        </main>
    </body>
</html>
