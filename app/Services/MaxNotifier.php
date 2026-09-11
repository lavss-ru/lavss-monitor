<?php

namespace App\Services;

use App\Models\Vps;
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

    public function sendWebsiteAggregate(\Illuminate\Support\Collection $websites, bool $update): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }
        $lines = [$update ? '🔴 Изменение списка недоступных сайтов' : '🔴 Сайты недоступны', ''];
        foreach ($websites as $website) {
            $lines[] = "{$website->name} — {$website->url}";
        }
        return $this->send(implode("\n", $lines), 'websites', $update ? 'update' : 'down', 'website');
    }

    public function sendWebsiteAggregateRecovery(): bool
    {
        return $this->isConfigured()
            && $this->send("🟢 Работа сайтов восстановлена\n\nВсе контролируемые сайты доступны.", 'websites', 'recovery', 'website');
    }

    public function sendLocalDevice(\App\Models\LocalDevice $device, bool $recovery): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }
        $text = implode("\n", [
            $recovery ? '🟢 Локальное устройство снова доступно' : '🔴 Локальное устройство недоступно',
            '', "Устройство: {$device->name}", "Площадка: {$device->location->name}",
            "TCP: {$device->endpoint()}",
            'Время: '.($recovery ? $device->recovery_pending_at : $device->incident_confirmed_at)->format('d.m.Y H:i:s T'),
        ]);
        return $this->send($text, $device->name, $recovery ? 'recovery' : 'down', 'local_device');
    }

    /**
     * Perform the actual HTTP POST to MAX API.
     * All exceptions are caught so they never propagate to the caller.
     */
    private function send(string $text, string $sourceName, string $kind, string $sourceType = 'vps'): bool
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

            if (! $response->successful() || (in_array($sourceType, ['website', 'local_device'], true)
                && ($response->json('success') === false || $response->json('error') !== null || $response->json('code') !== null))) {
                Log::error($sourceType === 'website' ? 'MaxNotifier: MAX API rejected website notification' : 'MaxNotifier: MAX API returned non-2xx response', [
                    $sourceType => $sourceName,
                    'kind'   => $kind,
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
                return false;
            }
            return true;
        } catch (\Throwable $e) {
            Log::error('MaxNotifier: failed to send MAX notification', [
                $sourceType => $sourceName,
                'kind'  => $kind,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
