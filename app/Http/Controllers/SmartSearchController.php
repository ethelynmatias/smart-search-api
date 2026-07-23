<?php

namespace App\Http\Controllers;

use App\Http\Requests\UKIndividualRequest;
use App\Services\LogService;
use App\Services\SmartSearch\DTOs\UKIndividualRequest as UKIndividualData;
use App\Services\SmartSearch\Exceptions\SmartSearchException;
use App\Services\SmartSearch\UKIndividualService;
use Illuminate\Http\JsonResponse;

class SmartSearchController extends Controller
{
    public function __construct(
        protected UKIndividualService $ukIndividuals,
        protected LogService $logService,
    ) {}

    /**
     * Run a UK individual AML check.
     */
    public function ukIndividual(UKIndividualRequest $request): JsonResponse
    {
        $data = UKIndividualData::fromArray($request->validated());

        try {
            $result = $this->ukIndividuals->create($data);
        } catch (SmartSearchException $e) {
            $this->logService->api('SmartSearch UK individual check failed', [
                'status' => $e->status,
                'error' => $e->getMessage(),
                'errors' => $e->errors,
            ]);

            return response()->json(['message' => $e->getMessage()], 502);
        }

        $this->logService->api('SmartSearch UK individual check created', $result);

        return response()->json(['data' => $result], 201);
    }
}
