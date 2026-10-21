<?php

namespace App\Http\Controllers;

use App\Services\SmartSearch\Exceptions\SmartSearchException;
use App\Services\SmartSearch\SmartDocService;
use Closure;
use Illuminate\Http\Response;

class SmartSearchController extends Controller
{
    public function __construct(
        protected SmartDocService $smartDocService,
    ) {}


    public function documents(string $token): Response
    {

       
    }

    public function documentCategories(string $token): Response
    {
        $accessToken = config('logs.access_token');

        abort_unless(filled($accessToken) && hash_equals($accessToken, $token), 404);

        return $this->printed([
            'categories' => $this->attempt(fn () => $this->smartDocService->listCategories()),
        ]);
    }

    /**
     * List the document types available to the account.
     */
    public function documentTypes(string $token): Response
    {
        $accessToken = config('logs.access_token');

        abort_unless(filled($accessToken) && hash_equals($accessToken, $token), 404);

        return $this->printed([
            'types' => $this->attempt(fn () => $this->smartDocService->listDocumentTypes()),
        ]);
    }

    /**
     * Fetch a single document search by ID.
     */
    public function document(string $token): Response
    {
        $accessToken = config('logs.access_token');

        abort_unless(filled($accessToken) && hash_equals($accessToken, $token), 404);

        // Fixed value for now: Real Inbound Ltd.
        $searchId = '11983718';

        return $this->printed([
            'pdf' => $this->attempt(fn () => $this->smartDocService->getPdfLink($searchId)),
            'document' => $this->attempt(fn () => $this->smartDocService->getDocument($searchId)),
        ]);
    }

    protected function attempt(Closure $call): mixed
    {
        try {
            return $call();
        } catch (SmartSearchException $e) {
            return [
                'error' => $e->getMessage(),
                'status' => $e->status,
                'errors' => $e->errors,
            ];
        }
    }

    protected function printed(array $output): Response
    {
        return response('<pre>'.e(print_r($output, true)).'</pre>');
    }
}
