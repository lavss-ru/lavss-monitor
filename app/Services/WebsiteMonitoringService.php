<?php

namespace App\Services;

use App\Models\Event;
use App\Models\Website;
use Illuminate\Support\Str;

class WebsiteMonitoringService
{
    public function __construct(
        private WebsiteHealthCheckService $healthCheck,
        private MaxNotifier $notifier,
    ) {
    }

    /**
     * @return array{status: string, http_status: int|null, response_ms: int, previous_status: string|null, event_created: bool}
     */
    public function monitor(Website $website): array
    {
        $previousStatus = $website->status;
        $result = $this->healthCheck->check($website);
        $website->refresh();

        $old = $previousStatus ?? 'unknown';
        $new = $website->status;
        $down = $new === 'offline' && in_array($old, ['unknown', 'online'], true);
        $recovery = $old === 'offline' && $new === 'online';

        if ($down || $recovery) {
            $http = $website->last_http_status !== null
                ? 'HTTP '.$website->last_http_status
                : 'HTTP-ответ не получен';
            $state = $down ? 'недоступен' : 'восстановлен';

            Event::create([
                'type' => 'website',
                'source_id' => $website->id,
                'severity' => $down ? 'warning' : 'info',
                'title' => Str::limit("Сайт {$website->name} {$state}", 255, ''),
                'message' => "Сайт: {$website->name}. URL: {$website->url}. {$http}. Длительность проверки: {$website->last_response_ms} ms.",
                'occurred_at' => $website->last_checked_at,
                'resolved_at' => null,
            ]);

            if ($down) {
                $this->notifier->sendWebsiteDown($website);
            } else {
                $this->notifier->sendWebsiteRecovery($website);
            }
        }

        return [
            'status' => $new,
            'http_status' => $result['http_status'],
            'response_ms' => $result['response_ms'],
            'previous_status' => $previousStatus,
            'event_created' => $down || $recovery,
        ];
    }
}
