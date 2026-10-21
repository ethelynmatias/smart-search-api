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
