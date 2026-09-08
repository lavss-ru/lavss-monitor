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
        $transportUrl = $this->transportUrl($website->url);

        $startTime = microtime(true);

        try {
            $response = Http::timeout($this->timeout)
                ->withOptions([
                    'verify' => true,
                    'allow_redirects' => ['max' => 5, 'protocols' => ['http', 'https']],
                ])
                ->get($transportUrl);

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

    private function transportUrl(string $url): string
    {
        // Reject controls before parse_url can silently replace them.
        $parts = parse_url($url);
        if (preg_match('/[\x00-\x20\x7f]/', $url) || $parts === false
            || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || empty($parts['host'])) {
            throw new InvalidArgumentException('Website URL must have an HTTP or HTTPS hostname.');
        }

        $hostname = $parts['host'];
        if (filter_var($hostname, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            || (str_starts_with($hostname, '[') && str_ends_with($hostname, ']')
                && filter_var(substr($hostname, 1, -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6))) {
            return $url;
        }

        // Checking the extension explicitly prevents a Composer polyfill fallback.
        if (! extension_loaded('intl') || ! function_exists('idn_to_ascii')
            || ! defined('INTL_IDNA_VARIANT_UTS46')) {
            throw new \LogicException('Website DNS hostname conversion requires native PHP intl with UTS46.');
        }

        $ascii = idn_to_ascii($hostname, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46, $info);
        if ($ascii === false || ($info['errors'] ?? 0) !== 0) {
            throw new InvalidArgumentException('Website URL has an invalid IDNA hostname.');
        }

        // Replace only host bytes in the authority, preserving userinfo, explicit
        // port and the entire suffix without decoding or re-encoding anything.
        $authorityStart = strlen($parts['scheme']) + 3;
        $authorityLength = strcspn($url, '/?#', $authorityStart);
        $authority = substr($url, $authorityStart, $authorityLength);
        $at = strrpos($authority, '@');
        $hostStart = $authorityStart + ($at === false ? 0 : $at + 1);

        return substr_replace($url, $ascii, $hostStart, strlen($hostname));
    }
}
