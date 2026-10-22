<?php

namespace App\Http\Controllers;

use App\Enums\LogType;
use App\Repositories\Contracts\LogRepositoryInterface;
use App\Support\StorageLogReader;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class LogController extends Controller
{
    public function __construct(
        protected LogRepositoryInterface $logs,
        protected StorageLogReader $reader,
    ) {}

    /**
     * Display a listing of the logs.
     *
     * The route's PreventIndexing middleware keeps this page, and the 404 for a
     * wrong token, out of search engines.
     */
    public function index(Request $request, string $token): Response
    {
        $accessToken = config('logs.access_token');

        abort_unless(filled($accessToken) && hash_equals($accessToken, $token), 404);

        $ssid = trim((string) $request->query('ssid'));
        $dateFrom = $this->normaliseDate($request->query('date_from'), today()->toDateString());
        $dateTo = $this->normaliseDate($request->query('date_to'), $dateFrom);

        if ($dateFrom > $dateTo) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }

        return response()->view('logs.index', [
            'token' => $token,
            'ssid' => $ssid,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'logs' => $this->logs->paginate(
                $request->enum('type', LogType::class),
                $request->query('group'),
                search: $ssid,
                dateFrom: $dateFrom,
                dateTo: $dateTo,
            ),
            'types' => LogType::cases(),
        ]);
    }

    public function storage(Request $request, string $token): Response
    {
        $accessToken = config('logs.access_token');

        abort_unless(filled($accessToken) && hash_equals($accessToken, $token), 404);

        $files = $this->reader->files();

        // Falls back to the most recently written file, which is the one a
        // daily channel is still appending to.
        $file = $request->query('file');
        $file = in_array($file, $files, true) ? $file : ($files[0] ?? null);

        $level = trim((string) $request->query('level'));
        $search = trim((string) $request->query('q'));

        return response()->view('logs.storage', [
            'token' => $token,
            'files' => $files,
            'file' => $file,
            'level' => $level,
            'search' => $search,
            'levels' => $this->reader->levels($file),
            'entries' => $this->reader->paginate($file, $level, $search),
        ]);
    }

    protected function normaliseDate(mixed $value, string $fallback): string
    {
        try {
            return Carbon::createFromFormat('!Y-m-d', trim((string) $value))->format('Y-m-d');
        } catch (\Throwable) {
            return $fallback;
        }
    }
}
