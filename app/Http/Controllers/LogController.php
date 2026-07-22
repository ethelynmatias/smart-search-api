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
    public function index(Request $request): View
    {
        return view('logs.index', [
            'logs' => $this->logs->paginate($request->enum('type', LogType::class)),
            'types' => LogType::cases(),
        ]);
    }
}
