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

    /**
     * Look up a company, then pull the PDF link and PDF of each of its documents.
     *
     * Guarded by the log page's access token, as the documents carry personal
     * data. Prints the raw responses for now.
     */
    public function documents(string $token): Response
    {
        $accessToken = config('logs.access_token');

        abort_unless(filled($accessToken) && hash_equals($accessToken, $token), 404);

          //exit("in1");

        // Fixed values for now: Real Inbound Ltd.
        $companyNumber = '11983718';

        // The document list is filtered by a SmartSearch search subject ID,
        // which the company lookup does not return. Set it once known.
        $subjectId = null;

        $output = [
            'lookup' => $this->attempt(fn () => $this->smartDocService->findUkBusiness($companyNumber)),
        ];

        if (blank($subjectId)) {
            $output['documents'] = 'Skipped: no SmartSearch subject ID set.';

            return $this->printed($output);
        }

        $documents = $this->attempt(fn () => $this->smartDocService->listAllDocuments(
            subject: $subjectId,
            cabinet: 'company',
            category: 'company',
            documentType: 'report',
            retrieved: 'false',
            page: 1,
            size: 25,
            include: 'types,categories',
        ));

        $output['documents'] = $documents;

        foreach ($documents['data'] ?? [] as $document) {
            $documentId = $document['id'];

            $output['pdfs'][$documentId] = [
                'pdf_link' => $this->attempt(fn () => $this->smartDocService->getPdfLink($documentId)),
                'pdf' => $this->attempt(function () use ($documentId) {
                    $pdf = $this->smartDocService->getPdf($documentId);

                    return [
                        'content_type' => $pdf->header('Content-Type'),
                        'bytes' => strlen($pdf->body()),
                    ];
                }),
            ];
        }

        return $this->printed($output);
    }

    /**
     * Run one SmartSearch call, returning its error in place of throwing so
     * every step's outcome is printed.
     */
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
