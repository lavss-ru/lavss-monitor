<?php

namespace App\Services;

use App\Models\Vps;
use App\Models\Website;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MaxNotifier
{
    /**
     * Return true if MAX credentials are fully configured.
     * Reads config lazily so test overrides via config([...]) are always honoured.
     */
    private function isConfigured(): bool
    {
        return (bool) (config('services.max.bot_token') ?: null)
            && (bool) (config('services.max.user_id')   ?: null);
    }

    /**
     * Send a DOWN alert when VPS transitions to offline.
     *
     * Used for: online → offline, unknown → offline
     */
    public function sendDown(Vps $vps): void
    {
        if (! $this->isConfigured()) {
            return;
        }

        $timestamp = now()->format('d.m.Y H:i:s T');

        $text = implode("\n", [
            '🔴 VPS недоступен',
            '',
            "Сервер: {$vps->name}",
            "Проверка: {$vps->ip_address}:{$vps->check_port}",
            "Время: {$timestamp}",
        ]);

        $this->send($text, $vps->name, 'down');
    }

    /**
     * Send a RECOVERY alert when VPS transitions from offline to online.
     *
     * Used for: offline → online
     * Downtime duration is omitted — no reliable offline_since field exists.
     */
    public function sendRecovery(Vps $vps): void
    {
        if (! $this->isConfigured()) {
            return;
        }

        $timestamp = now()->format('d.m.Y H:i:s T');

        $text = implode("\n", [
            '🟢 VPS снова доступен',
            '',
            "Сервер: {$vps->name}",
            "Проверка: {$vps->ip_address}:{$vps->check_port}",
            "Время восстановления: {$timestamp}",
        ]);

        $this->send($text, $vps->name, 'recovery');
    }

    public function sendWebsiteDown(Website $website): void
    {
        $this->sendWebsite($website, false);
    }

    public function sendWebsiteRecovery(Website $website): void
    {
        $this->sendWebsite($website, true);
    }

    private function sendWebsite(Website $website, bool $recovery): void
    {
        if (! $this->isConfigured()) {
            return;
        }

        $http = $website->last_http_status !== null
            ? 'HTTP '.$website->last_http_status
            : 'HTTP-ответ не получен';
        $timestamp = ($website->last_checked_at ?? now())->format('d.m.Y H:i:s T');
        $timeLabel = $recovery ? 'Время восстановления' : 'Время';
        $text = implode("\n", [
            $recovery ? '🟢 Сайт снова доступен' : '🔴 Сайт недоступен',
            '',
            "Сайт: {$website->name}",
            "URL: {$website->url}",
            "Результат: {$http}",
            "Длительность проверки: {$website->last_response_ms} ms",
            "{$timeLabel}: {$timestamp}",
        ]);

        $this->send($text, $website->name, $recovery ? 'recovery' : 'down', 'website');
    }

    /**
     * Perform the actual HTTP POST to MAX API.
     * All exceptions are caught so they never propagate to the caller.
     */
    private function send(string $text, string $sourceName, string $kind, string $sourceType = 'vps'): void
    {
        $botToken = (string) config('services.max.bot_token');
        $userId   = (string) config('services.max.user_id');
        $apiUrl   = (string) config('services.max.api_url', 'https://platform-api2.max.ru');

        try {
            $response = Http::withHeaders([
                'Authorization' => $botToken,
            ])
                ->timeout(5)
                ->post("{$apiUrl}/messages?user_id={$userId}", [
                    'text' => $text,
                ]);

            if (! $response->successful()) {
                Log::error('MaxNotifier: MAX API returned non-2xx response', [
                    $sourceType => $sourceName,
                    'kind'   => $kind,
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('MaxNotifier: failed to send MAX notification', [
                $sourceType => $sourceName,
                'kind'  => $kind,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
