<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SmartSearchWebhookController extends Controller
{
    /**
     * Handle incoming SmartSearch webhook events.
     */
    public function __invoke(Request $request): JsonResponse
    {
        return response()->json(['message' => 'ok']);
    }
}
