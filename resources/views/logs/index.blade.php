<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- The url carries an access token and the payloads hold personal data,
             so this page is kept out of search engines and off referrers. --}}
        <meta name="robots" content="noindex, nofollow, noarchive, nosnippet, noimageindex">
        <meta name="referrer" content="no-referrer">

        <title>Logs — {{ config('app.name', 'Smart Search API') }}</title>

        <link rel="stylesheet" href="{{ asset('css/logs.css') }}">
    </head>
    <body>
        {{-- Payloads are only worth the room once a single group, or a search
             narrow enough to have found something, is in view. --}}
        @php($showPayload = filled(request('group')) || filled($ssid))

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

                <form class="search" method="GET" action="{{ route('logs.index', ['token' => $token]) }}">
                    {{-- The type filter is kept while searching; a group is not,
                         since a search spans whichever groups hold the ssid. --}}
                    @if (request('type'))
                        <input type="hidden" name="type" value="{{ request('type') }}">
                    @endif

                    <input
                        type="search"
                        name="ssid"
                        value="{{ $ssid }}"
                        placeholder="Search by ssid, subject id or deal id"
                        autocomplete="off"
                    >
                    <button type="submit">Search</button>

                    @if (filled($ssid))
                        <a class="clear" href="{{ route('logs.index', array_filter(['token' => $token, 'type' => request('type')])) }}">&times; clear</a>
                    @endif
                </form>
            </header>

            @if (filled($ssid))
                <div class="group-filter">
                    Searching payloads for <code>{{ $ssid }}</code>
                    <span class="group-count">{{ $logs->total() }} {{ Str::plural('result', $logs->total()) }}</span>
                </div>
            @endif

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
                                <td colspan="6" class="empty">
                                    @if (filled($ssid))
                                        Nothing found for <code>{{ $ssid }}</code>.
                                    @else
                                        No logs recorded yet.
                                    @endif
                                </td>
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
