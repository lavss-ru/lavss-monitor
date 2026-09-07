<?php

namespace App\Services;

use App\Models\Website;
use GuzzleHttp\Exception\TransferException;
use InvalidArgumentException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class WebsiteHealthCheckService
{
    /**
     * Timeout in seconds for the HTTP request.
     */
    private int $timeout = 10;

    /**
     * Perform an HTTP health-check against the given Website.
     *
     * Uses Laravel HTTP Client — no shell commands involved.
     * TLS verification remains enabled (no verify=false).
     * Redirects are followed automatically.
     *
     * Updates the Website model in the database with the result.
     *
     * Rules:
     *   HTTP 200–399 → status = 'online',  http_status = real code
     *   HTTP 400–599 → status = 'offline', http_status = real code
     *   ConnectionException / any transport error
     *              → status = 'offline', http_status = null
     *
     * response_ms is always an integer >= 0.
     *
     * @return array{status: string, http_status: int|null, response_ms: int}
     */
    public function check(Website $website): array
    {
        if (! in_array(strtolower((string) parse_url($website->url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            throw new InvalidArgumentException('Website URL must use HTTP or HTTPS.');
        }

        $startTime = microtime(true);

        try {
            $response = Http::timeout($this->timeout)
                ->withOptions([
                    'verify' => true,
                    'allow_redirects' => ['max' => 5, 'protocols' => ['http', 'https']],
                ])
                ->get($website->url);

            $elapsed    = microtime(true) - $startTime;
            $responseMs = max(0, (int) round($elapsed * 1000));
            $httpStatus = $response->status();

            // HTTP 200–399 → online; anything else → offline
            $status = ($httpStatus >= 200 && $httpStatus <= 399) ? 'online' : 'offline';

            $result = [
                'status'      => $status,
                'http_status' => $httpStatus,
                'response_ms' => $responseMs,
            ];
        } catch (ConnectionException|TransferException $e) {
            // DNS / connect / TLS transport-level failure
            $elapsed    = microtime(true) - $startTime;
            $responseMs = max(0, (int) round($elapsed * 1000));

            $result = [
                'status'      => 'offline',
                'http_status' => null,
                'response_ms' => $responseMs,
            ];
        }

        $website->update([
            'status'           => $result['status'],
            'last_checked_at'  => now(),
            'last_response_ms' => $result['response_ms'],
            'last_http_status' => $result['http_status'],
        ]);

        return $result;
    }
}
