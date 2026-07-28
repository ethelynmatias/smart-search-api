<?php

namespace App\Services\SmartSearch\Exceptions;

use Exception;
use Illuminate\Http\Client\Response;

class SmartSearchException extends Exception
{
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly ?array $errors = null,
    ) {
        parent::__construct($message, $status ?? 0);
    }

    public static function requestFailed(string $endpoint, Response $response): self
    {
        $detail = $response->json('errors.0.detail')
            ?? $response->json('errors.0.title')
            ?? $response->reason();

        return new self(
            "SmartSearch request to [{$endpoint}] failed: {$detail}",
            $response->status(),
            $response->json('errors'),
        );
    }

    public static function missingConfig(string $key): self
    {
        return new self("SmartSearch config [services.smartsearch.{$key}] is not set.");
    }
}
