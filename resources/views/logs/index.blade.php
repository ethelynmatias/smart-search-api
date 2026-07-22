<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>Logs — {{ config('app.name', 'Smart Search API') }}</title>

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
                padding: 2rem;
            }

            .container {
                max-width: 72rem;
                margin: 0 auto;
            }

            header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                margin-bottom: 1.5rem;
            }

            h1 {
                font-size: 1.5rem;
                font-weight: 700;
            }

            .filters a {
                display: inline-block;
                font-size: 0.8rem;
                font-weight: 600;
                text-decoration: none;
                color: #94a3b8;
                border: 1px solid #334155;
                border-radius: 9999px;
                padding: 0.3rem 0.9rem;
                margin-left: 0.4rem;
            }

            .filters a.active {
                color: #ff7a59;
                border-color: #ff7a59;
            }

            .table-wrap {
                overflow-x: auto;
                border: 1px solid #1e293b;
                border-radius: 0.5rem;
            }

            table {
                width: 100%;
                border-collapse: collapse;
                font-size: 0.9rem;
            }

            th,
            td {
                text-align: left;
                padding: 0.65rem 1rem;
                border-bottom: 1px solid #1e293b;
                vertical-align: top;
            }

            th {
                background: #111a2e;
                color: #94a3b8;
                font-size: 0.75rem;
                text-transform: uppercase;
                letter-spacing: 0.05em;
            }

            tr:last-child td {
                border-bottom: none;
            }

            .badge {
                display: inline-block;
                font-size: 0.7rem;
                font-weight: 600;
                text-transform: uppercase;
                letter-spacing: 0.05em;
                border-radius: 9999px;
                padding: 0.15rem 0.6rem;
            }

            .badge.webhook {
                color: #ff7a59;
                border: 1px solid #ff7a59;
            }

            .badge.api {
                color: #38bdf8;
                border: 1px solid #38bdf8;
            }

            pre {
                font-size: 0.75rem;
                color: #94a3b8;
                white-space: pre-wrap;
                word-break: break-all;
                max-width: 28rem;
            }

            .group-link {
                font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
                font-size: 0.8rem;
                color: #38bdf8;
                text-decoration: none;
            }

            .group-link:hover {
                text-decoration: underline;
            }

            .group-count {
                display: inline-block;
                font-size: 0.7rem;
                font-weight: 600;
                color: #94a3b8;
                border: 1px solid #334155;
                border-radius: 9999px;
                padding: 0.05rem 0.45rem;
                margin-left: 0.25rem;
            }

            .group-filter {
                display: flex;
                align-items: center;
                gap: 0.6rem;
                font-size: 0.85rem;
                color: #94a3b8;
                border: 1px solid #334155;
                border-radius: 0.5rem;
                padding: 0.5rem 1rem;
                margin-bottom: 1rem;
            }

            .group-filter code {
                font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
                color: #38bdf8;
            }

            .group-filter a {
                color: #f87171;
                text-decoration: none;
            }

            .empty {
                text-align: center;
                color: #64748b;
                padding: 3rem 1rem;
            }

            .pagination {
                margin-top: 1.25rem;
            }

            .pagination nav {
                display: flex;
                justify-content: center;
                gap: 0.25rem;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <header>
                <h1>Logs</h1>
                <nav class="filters">
                    <a href="{{ route('logs.index') }}" @class(['active' => ! request('type')])>All</a>
                    @foreach ($types as $type)
                        <a
                            href="{{ route('logs.index', ['type' => $type->value]) }}"
                            @class(['active' => request('type') === $type->value])
                        >{{ ucfirst($type->value) }}</a>
                    @endforeach
                </nav>
            </header>

            @if (request('group'))
                <div class="group-filter">
                    Showing group <code>{{ request('group') }}</code>
                    <a href="{{ route('logs.index', array_filter(['type' => request('type')])) }}">&times; clear</a>
                </div>
            @endif

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Group</th>
                            <th>Type</th>
                            <th>Message</th>
                            <th>Payload</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($logs as $log)
                            <tr>
                                <td>{{ $log->id }}</td>
                                <td>
                                    @if ($log->log_group_id)
                                        <a
                                            class="group-link"
                                            href="{{ route('logs.index', array_filter(['type' => request('type'), 'group' => $log->log_group_id])) }}"
                                            title="{{ $log->log_group_id }}"
                                        >{{ substr($log->log_group_id, 0, 8) }}</a>
                                        @if (($log->group_count ?? 1) > 1)
                                            <span class="group-count">&times;{{ $log->group_count }}</span>
                                        @endif
                                    @else
                                        —
                                    @endif
                                </td>
                                <td><span class="badge {{ $log->type->value }}">{{ $log->type->value }}</span></td>
                                <td>{{ $log->message ?? '—' }}</td>
                                <td>
                                    @if ($log->payload)
                                        <pre>{{ json_encode($log->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td>{{ $log->created_at->format('Y-m-d H:i:s') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="empty">No logs recorded yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="pagination">
                {{ $logs->links() }}
            </div>
        </div>
    </body>
</html>
