<?php

namespace App\Http\Controllers;

use App\Support\StorageLogReader;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class StorageLogController extends Controller
{
    public function __construct(
        protected StorageLogReader $reader,
    ) {}

    /**
     * Display the entries of one file under storage/logs.
     *
     * Guarded the same way as the database log page, and kept out of search
     * engines by the same PreventIndexing route middleware: the url carries the
     * access token, and a stack trace names paths and query values that no
     * crawler should hold on to.
     */
    public function index(Request $request, string $token): Response
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
}
