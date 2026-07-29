<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>Logs — {{ config('app.name', 'Smart Search API') }}</title>

        <link rel="stylesheet" href="{{ asset('css/logs.css') }}">
    </head>
    <body>
        {{-- Payloads are only worth the room once a single group is in view. --}}
        @php($showPayload = filled(request('group')))

        <div class="container">
            <header>
                <h1>Logs</h1>
                <nav class="filters">
                    <a href="{{ route('logs.index', ['token' => $token]) }}" @class(['active' => ! request('type')])>All</a>
                    @foreach ($types as $type)
                        <a
                            href="{{ route('logs.index', ['token' => $token, 'type' => $type->value]) }}"
                            @class(['active' => request('type') === $type->value])
                        >{{ ucfirst($type->value) }}</a>
                    @endforeach
                </nav>
            </header>

            @if (request('group'))
                <div class="group-filter">
                    Showing group <code>{{ request('group') }}</code>
                    <a href="{{ route('logs.index', array_filter(['token' => $token, 'type' => request('type')])) }}">&times; clear</a>
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
                                            href="{{ route('logs.index', array_filter(['token' => $token, 'type' => request('type'), 'group' => $log->log_group_id])) }}"
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
                                    @if (! $log->payload)
                                        <span class="muted">—</span>
                                    @elseif ($showPayload)
                                        <span class="muted">below</span>
                                    @elseif ($log->log_group_id)
                                        {{-- Off the group view the payload stays collapsed behind its group. --}}
                                        <a
                                            class="payload-link"
                                            href="{{ route('logs.index', array_filter(['token' => $token, 'type' => request('type'), 'group' => $log->log_group_id])) }}"
                                        >View payload</a>
                                    @else
                                        <span class="muted">—</span>
                                    @endif
                                </td>
                                <td class="nowrap">{{ $log->created_at->format('Y-m-d H:i:s') }}</td>
                            </tr>

                            {{-- In a group the payload gets the full width of the
                                 table rather than one cramped column. --}}
                            @if ($showPayload && $log->payload)
                                <tr class="payload-row">
                                    <td colspan="6">
                                        <details open>
                                            <summary>
                                                <span class="payload-title">{{ $log->message ?? 'Payload' }}</span>
                                                <span class="payload-meta">#{{ $log->id }}</span>
                                            </summary>
                                            <pre class="payload">{{ json_encode($log->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                        </details>
                                    </td>
                                </tr>
                            @endif
                        @empty
                            <tr>
                                <td colspan="6" class="empty">No logs recorded yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="pagination">
                {{ $logs->appends(request()->query())->links('vendor.pagination.logs') }}
            </div>
        </div>
    </body>
</html>
