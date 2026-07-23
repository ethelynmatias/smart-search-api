<?php

namespace App\Http\Controllers;

use App\Enums\LogType;
use App\Repositories\Contracts\LogRepositoryInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class LogController extends Controller
{
    public function __construct(
        protected LogRepositoryInterface $logs,
    ) {}

    /**
     * Display a listing of the logs.
     */
    public function index(Request $request, string $token): View
    {
        $accessToken = config('logs.access_token');

        abort_unless(filled($accessToken) && hash_equals($accessToken, $token), 404);

        return view('logs.index', [
            'token' => $token,
            'logs' => $this->logs->paginate(
                $request->enum('type', LogType::class),
                $request->query('group'),
            ),
            'types' => LogType::cases(),
        ]);
    }
}
