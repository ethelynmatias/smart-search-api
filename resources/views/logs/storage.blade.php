<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- The url carries an access token and a trace names paths and query
             values, so this page is kept out of search engines and off referrers. --}}
        <meta name="robots" content="noindex, nofollow, noarchive, nosnippet, noimageindex">
        <meta name="referrer" content="no-referrer">

        <title>Storage logs — {{ config('app.name', 'Smart Search API') }}</title>

        <link rel="stylesheet" href="{{ asset('css/logs.css') }}">
    </head>
    <body>
        <div class="container">
            <header>
                <h1>Storage logs</h1>

                <nav class="filters">
                    <a href="{{ route('logs.index', ['token' => $token]) }}">Database</a>
                    <a href="{{ route('logs.storage', ['token' => $token]) }}" class="active">Storage</a>
                </nav>
            </header>

            <form class="toolbar" method="GET" action="{{ route('logs.storage', ['token' => $token]) }}">
                <label class="field">
                    <span>File</span>
                    <select name="file" onchange="this.form.submit()">
                        @forelse ($files as $available)
                            <option value="{{ $available }}" @selected($available === $file)>{{ $available }}</option>
                        @empty
                            <option value="">No log files</option>
                        @endforelse
                    </select>
                </label>

                <label class="field">
                    <span>Level</span>
                    <select name="level" onchange="this.form.submit()">
                        <option value="">All</option>
                        @foreach ($levels as $available)
                            <option value="{{ $available }}" @selected(strcasecmp($available, $level) === 0)>{{ $available }}</option>
                        @endforeach
                    </select>
                </label>

                <div class="search">
                    <input
                        type="search"
                        name="q"
                        value="{{ $search }}"
                        placeholder="Search message, context or trace"
                        autocomplete="off"
                    >
                    <button type="submit">Search</button>

                    @if (filled($search))
                        <a class="clear" href="{{ route('logs.storage', array_filter(['token' => $token, 'file' => $file, 'level' => $level])) }}">&times; clear</a>
                    @endif
                </div>
            </form>

            @if (filled($search))
                <div class="group-filter">
                    Searching <code>{{ $file }}</code> for <code>{{ $search }}</code>
                    <span class="group-count">{{ $entries->total() }} {{ Str::plural('entry', $entries->total()) }}</span>
                </div>
            @endif

            {{-- Oldest step at the top, newest at the bottom, the same way the
                 file itself is written and the same way the group trail reads. --}}
            @php($previous = null)

            <ol class="timeline">
                @forelse ($entries as $entry)
                    @php($level = strtolower($entry['level']))
                    @php($gap = $previous && $entry['at'] ? $previous->diffInSeconds($entry['at']) : null)

                    <li class="timeline-step">
                        <div class="timeline-rail">
                            <span class="timeline-dot {{ $level }}"></span>
                            <span class="timeline-index">{{ $entries->firstItem() + $loop->index }}</span>
                        </div>

                        <div class="timeline-card">
                            <div class="timeline-head">
                                <span class="badge {{ $level }}">{{ $entry['level'] }}</span>
                                <span class="timeline-title">{{ Str::limit($entry['message'], 300) }}</span>
                                <time class="timeline-time nowrap">{{ $entry['at']?->format('Y-m-d H:i:s') ?? '—' }}</time>
                            </div>

                            <div class="timeline-meta">
                                <span class="payload-meta">{{ $entry['channel'] }}</span>

                                @if ($gap !== null)
                                    @php($gap = (int) round(abs($gap)))
                                    <span class="timeline-gap">
                                        +{{ $gap < 60 ? $gap.'s' : ($gap < 3600 ? round($gap / 60).'m' : round($gap / 3600, 1).'h') }}
                                    </span>
                                @endif
                            </div>

                            @if ($entry['context'])
                                <details {{ $entry['trace'] ? '' : 'open' }}>
                                    <summary><span class="payload-title">Context</span></summary>
                                    <pre class="payload">{{ json_encode($entry['context'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                </details>
                            @endif

                            {{-- A trace is long enough to bury the entries under it,
                                 so it stays collapsed until it is asked for. --}}
                            @if ($entry['trace'])
                                <details>
                                    <summary><span class="payload-title">Stack trace</span></summary>
                                    <pre class="payload">{{ $entry['trace'] }}</pre>
                                </details>
                            @endif
                        </div>
                    </li>

                    @php($previous = $entry['at'] ?? $previous)
                @empty
                    <li class="empty">
                        @if (blank($file))
                            No log files under storage/logs.
                        @elseif (filled($search) || filled($level))
                            Nothing in <code>{{ $file }}</code> matches that filter.
                        @else
                            <code>{{ $file }}</code> is empty.
                        @endif
                    </li>
                @endforelse
            </ol>

            @if ($entries->hasPages())
                <div class="pagination">
                    {{ $entries->appends(request()->query())->links('vendor.pagination.logs') }}
                </div>
            @endif
        </div>
    </body>
</html>
