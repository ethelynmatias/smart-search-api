<?php

namespace App\Http\Controllers;

use App\Enums\LogType;
use App\Repositories\Contracts\LogRepositoryInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class LogController extends Controller
{
    public function __construct(
        protected LogRepositoryInterface $logs,
    ) {}

    /**
     * Display a listing of the logs.
     *
     * Sent with X-Robots-Tag as well as the view's robots meta tag: a crawler
     * that reaches the page from a link or a leaked url obeys the header even
     * where robots.txt was never fetched, and the header is what removes a page
     * that has already been indexed.
     */
    public function index(Request $request, string $token): Response
    {
        $accessToken = config('logs.access_token');

        abort_unless(filled($accessToken) && hash_equals($accessToken, $token), 404);

        $ssid = trim((string) $request->query('ssid'));

        return response()
            ->view('logs.index', [
                'token' => $token,
                'ssid' => $ssid,
                'logs' => $this->logs->paginate(
                    $request->enum('type', LogType::class),
                    $request->query('group'),
                    search: $ssid,
                ),
                'types' => LogType::cases(),
            ])
            ->header('X-Robots-Tag', 'noindex, nofollow, noarchive, nosnippet, noimageindex')
            ->header('Referrer-Policy', 'no-referrer')
            ->header('Cache-Control', 'no-store, private');
    }
}
