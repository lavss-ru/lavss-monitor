<?php

namespace App\Services;

use App\Models\LocalDevice;
use App\Models\Location;
use App\Models\Vps;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MaxNotifier
{
    /**
     * Send a DOWN alert when VPS transitions to offline.
     *
     * Used for: online → offline, unknown → offline
     */
    public function sendDown(Vps $vps, ?NotificationPolicyService $policy = null): bool
    {
        $policy ??= NotificationPolicyService::load();
        if (! $policy->maxConfigured()) {
            return false;
        }

        $timestamp = now()->setTimezone($policy->timezone())->format('d.m.Y H:i:s T');

        $text = implode("\n", [
            '🔴 VPS недоступен',
            '',
            "Сервер: {$vps->name}",
            "Проверка: {$vps->ip_address}:{$vps->check_port}",
            "Время: {$timestamp}",
        ]);

        return $policy->decision('vps', false) === 'deliver' && $this->send($text, $vps->name, 'down', 'vps', $policy);
    }

    /**
     * Send a RECOVERY alert when VPS transitions from offline to online.
     *
     * Used for: offline → online
     * Downtime duration is omitted — no reliable offline_since field exists.
     */
    public function sendRecovery(Vps $vps, ?NotificationPolicyService $policy = null): bool
    {
        $policy ??= NotificationPolicyService::load();
        if (! $policy->maxConfigured()) {
            return false;
        }

        $timestamp = now()->setTimezone($policy->timezone())->format('d.m.Y H:i:s T');

        $text = implode("\n", [
            '🟢 VPS снова доступен',
            '',
            "Сервер: {$vps->name}",
            "Проверка: {$vps->ip_address}:{$vps->check_port}",
            "Время восстановления: {$timestamp}",
        ]);

        return $policy->decision('vps', true) === 'deliver' && $this->send($text, $vps->name, 'recovery', 'vps', $policy);
    }

    public function sendWebsiteAggregate(Collection $websites, bool $update, ?NotificationPolicyService $policy = null): bool
    {
        $policy ??= NotificationPolicyService::load();
        if (! $policy->maxConfigured()) {
            return false;
        }
        $lines = [$update ? '🔴 Изменение списка недоступных сайтов' : '🔴 Сайты недоступны', ''];
        foreach ($websites as $website) {
            $lines[] = "{$website->name} — {$website->url}";
        }

        return $policy->decision('website', false) === 'deliver' && $this->send(implode("\n", $lines), 'websites', $update ? 'update' : 'down', 'website', $policy);
    }

    public function sendWebsiteAggregateRecovery(?NotificationPolicyService $policy = null): bool
    {
        $policy ??= NotificationPolicyService::load();

        return $policy->maxConfigured() && $policy->decision('website', true) === 'deliver'
            && $this->send("🟢 Работа сайтов восстановлена\n\nВсе контролируемые сайты доступны.", 'websites', 'recovery', 'website', $policy);
    }

    public function sendLocalDevice(LocalDevice $device, bool $recovery, ?NotificationPolicyService $policy = null): bool
    {
        $policy ??= NotificationPolicyService::load();
        if (! $policy->maxConfigured()) {
            return false;
        }
        $text = implode("\n", [
            $recovery ? '🟢 Локальное устройство снова доступно' : '🔴 Локальное устройство недоступно',
            '', "Устройство: {$device->name}", "Площадка: {$device->location->name}",
            "TCP: {$device->endpoint()}",
            'Время: '.($recovery ? $device->recovery_pending_at : $device->incident_confirmed_at)->copy()->setTimezone($policy->timezone())->format('d.m.Y H:i:s T'),
        ]);

        return $policy->decision('local_device', $recovery) === 'deliver' && $this->send($text, $device->name, $recovery ? 'recovery' : 'down', 'local_device', $policy);
    }

    public function sendLocation(Location $location, bool $recovery, ?NotificationPolicyService $policy = null): bool
    {
        $policy ??= NotificationPolicyService::load();
        if (! $policy->maxConfigured()) {
            return false;
        }
        $text = implode("\n", [
            $recovery ? '🟢 Площадка снова доступна' : '🔴 Площадка недоступна',
            '', "Площадка: {$location->name}",
            'Подключение: '.($location->connection_type === 'wireguard' ? 'WireGuard' : $location->connection_type),
            "Контроль: {$location->endpoint()}",
            'Время: '.($recovery ? $location->recovery_pending_at : $location->incident_confirmed_at)->copy()->setTimezone($policy->timezone())->format('d.m.Y H:i:s T'),
        ]);

        return $policy->decision('location', $recovery) === 'deliver'
            && $this->send($text, $location->name, $recovery ? 'recovery' : 'down', 'location', $policy);
    }

    /**
     * Perform the actual HTTP POST to MAX API.
     * All exceptions are caught so they never propagate to the caller.
     */
    private function send(string $text, string $sourceName, string $kind, string $sourceType, NotificationPolicyService $policy): bool
    {
        $botToken = (string) config('services.max.bot_token');
        $userId = rawurlencode((string) $policy->recipient());
        $apiUrl = (string) config('services.max.api_url', 'https://platform-api2.max.ru');

        try {
            $response = Http::withHeaders([
                'Authorization' => $botToken,
            ])
                ->timeout(5)
                ->post("{$apiUrl}/messages?user_id={$userId}", [
                    'text' => $text,
                ]);

            if (! $response->successful() || $response->json('success') === false
                || $response->json('error') !== null || $response->json('code') !== null) {
                Log::error($sourceType === 'website' ? 'MaxNotifier: MAX API rejected website notification' : 'MaxNotifier: MAX API returned non-2xx response', [
                    $sourceType => $sourceName,
                    'kind' => $kind,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::error('MaxNotifier: failed to send MAX notification', [
                $sourceType => $sourceName,
                'kind' => $kind,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
