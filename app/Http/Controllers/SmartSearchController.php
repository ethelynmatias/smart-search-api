<?php

namespace App\Http\Controllers;

use App\DTOs\SmartSearch\AMLData;
use App\DTOs\SmartSearch\SmartDocData;
use App\Http\Requests\AMLRequest;
use App\Http\Requests\SmartDocRequest;
use App\Models\SmartSearchSearch;
use App\Services\LogService;
use App\Services\SmartSearch\AMLService;
use App\Services\SmartSearch\Exceptions\SmartSearchException;
use App\Services\SmartSearch\SmartDocService;
use Illuminate\Http\JsonResponse;

class SmartSearchController extends Controller
{
    public function __construct(
        protected AMLService $amlService,
        protected SmartDocService $smartDocService,
        protected LogService $logService,
    ) {}

    /**
     * Run an AML check.
     */
    public function aml(AMLRequest $request): JsonResponse
    {
        $data = AMLData::fromArray($request->validated());

        try {
            $result = $this->amlService->create($data);
        } catch (SmartSearchException $e) {
            return $this->failed('aml', $e);
        }

        $search = $this->storeSearch('aml', $data->clientRef, $data->toPayload(), $result);

        $this->logService->api('SmartSearch AML check created', $result);

        return response()->json(['data' => $result, 'search_id' => $search->id], 201);
    }

    /**
     * Create a SmartDoc (document verification) check.
     */
    public function smartDoc(SmartDocRequest $request): JsonResponse
    {
        $data = SmartDocData::fromArray($request->validated());

        try {
            $result = $this->smartDocService->create($data);
        } catch (SmartSearchException $e) {
            return $this->failed('smartdoc', $e);
        }

        $search = $this->storeSearch('smartdoc', $data->clientRef, $data->toPayload(), $result);

        $this->logService->api('SmartSearch SmartDoc check created', $result);

        return response()->json(['data' => $result, 'search_id' => $search->id], 201);
    }

    protected function storeSearch(string $type, ?string $clientRef, array $payload, array $result): SmartSearchSearch
    {
        return SmartSearchSearch::create([
            'search_id' => $result['id'] ?? null,
            'type' => $type,
            'status' => $result['attributes']['status'] ?? null,
            'client_ref' => $clientRef,
            'payload' => $payload,
            'result' => $result,
        ]);
    }

    protected function failed(string $type, SmartSearchException $e): JsonResponse
    {
        $this->logService->api("SmartSearch {$type} check failed", [
            'status' => $e->status,
            'error' => $e->getMessage(),
            'errors' => $e->errors,
        ]);

        return response()->json(['message' => $e->getMessage()], 502);
    }
}
