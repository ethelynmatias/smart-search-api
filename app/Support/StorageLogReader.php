<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Read the Monolog files under storage/logs into structured entries.
 *
 * These are written as a header line — timestamp, channel, level, message —
 * followed by however many lines of context and stack trace the handler chose
 * to append. Splitting on the header is what turns a file that is mostly one
 * unreadable wall of trace back into entries a page can lay out.
 */
class StorageLogReader
{
    /**
     * Only the tail of a file is parsed: a log left to grow for weeks is not
     * worth holding in memory, and its oldest lines are not what is being read.
     */
    protected const MAX_BYTES = 5 * 1024 * 1024;

    protected const HEADER = '/^\[(\d{4}-\d{2}-\d{2}[ T][\d:.]+(?:[+-]\d{2}:\d{2})?)\] (\S+?)\.([A-Z]+): (.*)$/';

    /**
     * The log files present, most recently written first.
     *
     * @return array<int, string> basenames, safe to hand back as query values
     */
    public function files(): array
    {
        $files = glob(storage_path('logs/*.log')) ?: [];

        usort($files, fn (string $a, string $b) => filemtime($b) <=> filemtime($a));

        return array_map('basename', $files);
    }

    /**
     * Paginate one file's entries, oldest first, optionally filtered.
     *
     * @param  string|null  $level  a Monolog level name, matched case-insensitively
     * @param  string|null  $search  matched against the whole entry, trace included
     */
    public function paginate(?string $file, ?string $level = null, ?string $search = null, int $perPage = 25, ?int $page = null): LengthAwarePaginator
    {
        $entries = $this->entries($file)
            ->when(filled($level), fn (Collection $entries) => $entries->filter(
                fn (array $entry) => strcasecmp($entry['level'], (string) $level) === 0,
            ))
            ->when(filled($search), fn (Collection $entries) => $entries->filter(
                fn (array $entry) => Str::contains($entry['raw'], (string) $search, ignoreCase: true),
            ))
            ->values();

        $pages = max(1, (int) ceil($entries->count() / $perPage));

        // The newest lines are the ones being read, and they sit at the end of a
        // file kept in chronological order, so an unasked-for page lands there.
        $page ??= (int) request()->query('page');
        $page = $page > 0 ? min($page, $pages) : $pages;

        return new LengthAwarePaginator(
            $entries->forPage($page, $perPage)->values(),
            $entries->count(),
            $perPage,
            $page,
            ['path' => Paginator::resolveCurrentPath()],
        );
    }

    /**
     * The levels present in a file, so the filter only offers what is there.
     *
     * @return array<int, string>
     */
    public function levels(?string $file): array
    {
        return $this->entries($file)
            ->pluck('level')
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Parse a file into entries, oldest first.
     *
     * @return Collection<int, array{at: Carbon|null, channel: string, level: string, message: string, context: array|null, trace: string|null, raw: string}>
     */
    protected function entries(?string $file): Collection
    {
        $contents = $this->contents($file);

        if (blank($contents)) {
            return collect();
        }

        $entries = collect();
        $current = null;

        foreach (preg_split('/\r?\n/', $contents) as $line) {
            if (preg_match(self::HEADER, $line, $matches)) {
                if ($current !== null) {
                    $entries->push($this->finish($current));
                }

                $current = [
                    'at' => rescue(fn () => Carbon::parse($matches[1]), null, report: false),
                    'channel' => $matches[2],
                    'level' => $matches[3],
                    'message' => $matches[4],
                    'body' => [],
                ];

                continue;
            }

            // A line before the first header is the tail of an entry that was
            // cut in half by the byte cap, and has no header to belong to.
            if ($current !== null) {
                $current['body'][] = $line;
            }
        }

        if ($current !== null) {
            $entries->push($this->finish($current));
        }

        return $entries;
    }

    /**
     * Split an entry's message into its message, context and stack trace.
     */
    protected function finish(array $entry): array
    {
        $message = $entry['message'];
        $context = null;

        // Monolog appends the context as JSON on the end of the message line.
        if (preg_match('/^(.*?)\s(\{.*\}|\[.*\])\s*$/s', $message, $matches)) {
            $decoded = json_decode($matches[2], true);

            if (is_array($decoded)) {
                $message = trim($matches[1]);
                $context = $decoded;
            }
        }

        $trace = trim(implode("\n", $entry['body']));

        // An exception's context is not one line: its JSON holds a [stacktrace]
        // that Monolog writes across the lines below. It cannot be decoded, so
        // the opening fragment moves to the trace rather than sitting in the
        // title with the entry's actual message.
        if ($context === null && preg_match('/^(.*?)\s(\{".*)$/s', $message, $matches)) {
            $message = trim($matches[1]);
            $trace = trim($matches[2]."\n".$trace);
        }

        return [
            'at' => $entry['at'],
            'channel' => $entry['channel'],
            'level' => $entry['level'],
            'message' => $message !== '' ? $message : '(no message)',
            'context' => $context,
            'trace' => $trace !== '' ? $trace : null,
            'raw' => $entry['message']."\n".$trace,
        ];
    }

    /**
     * Read the tail of a file, rejecting any name that is not one of ours.
     */
    protected function contents(?string $file): string
    {
        // Compared against the real listing rather than sanitised, so no amount
        // of traversal in the query string reaches a path outside storage/logs.
        if (blank($file) || ! in_array($file, $this->files(), true)) {
            return '';
        }

        $path = storage_path('logs/'.$file);
        $size = filesize($path) ?: 0;

        if ($size <= self::MAX_BYTES) {
            return (string) file_get_contents($path);
        }

        return (string) file_get_contents($path, offset: $size - self::MAX_BYTES);
    }
}
