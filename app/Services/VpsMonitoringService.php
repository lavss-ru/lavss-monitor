<?php

namespace App\Services;

use App\Models\Event;
use App\Models\Vps;

class VpsMonitoringService
{
    public function __construct(private VpsHealthCheckService $healthCheck)
    {
    }

    /**
     * Run a health-check for one VPS, compare old/new status,
     * and create an Event only when the status transitions.
     *
     * @return array{
     *   status: string,
     *   response_ms: int|null,
     *   previous_status: string|null,
     *   event_created: bool,
     * }
     */
    public function monitor(Vps $vps): array
    {
        $previousStatus = $vps->status; // captures state before check

        $result = $this->healthCheck->check($vps);
        // After check(), $vps->refresh() not needed — check() calls $vps->update()
        // which updates the model in-place via mass-assignment.
        $vps->refresh();
        $newStatus = $vps->status;

        $eventCreated = $this->createEventIfNeeded($vps, $previousStatus, $newStatus, $result);

        return [
            'status'          => $newStatus,
            'response_ms'     => $result['response_ms'] ?? null,
            'previous_status' => $previousStatus,
            'event_created'   => $eventCreated,
        ];
    }

    /**
     * Determine if a status transition warrants an event and create it.
     *
     * Transitions that create events:
     *   online  → offline  : warning
     *   unknown → offline  : warning
     *   offline → online   : info (recovery)
     *
     * Transitions that do NOT create events:
     *   online  → online   : no change
     *   offline → offline  : no change
     *   unknown → online   : first-time success, not worth noising
     *   unknown → unknown  : not applicable
     */
    private function createEventIfNeeded(
        Vps $vps,
        ?string $oldStatus,
        string $newStatus,
        array $checkResult,
    ): bool {
        // Normalise null → 'unknown'
        $old = $oldStatus ?? 'unknown';

        // No transition — nothing to record
        if ($old === $newStatus) {
            return false;
        }

        // unknown → online: silent first-time success
        if ($old === 'unknown' && $newStatus === 'online') {
            return false;
        }

        // online → offline OR unknown → offline: warning
        if ($newStatus === 'offline') {
            Event::create([
                'type'        => 'vps',
                'severity'    => 'warning',
                'title'       => "VPS {$vps->name} недоступен",
                'message'     => "TCP connect к {$vps->ip_address}:{$vps->check_port} не удался.",
                'source_id'   => $vps->id,
                'occurred_at' => now(),
            ]);
            return true;
        }

        // offline → online: recovery info
        if ($old === 'offline' && $newStatus === 'online') {
            $ms = $checkResult['response_ms'] ?? null;
            $msText = $ms !== null ? ", отклик {$ms} ms." : '.';
            Event::create([
                'type'        => 'vps',
                'severity'    => 'info',
                'title'       => "VPS {$vps->name} восстановлен",
                'message'     => "TCP connect к {$vps->ip_address}:{$vps->check_port} снова успешен{$msText}",
                'source_id'   => $vps->id,
                'occurred_at' => now(),
            ]);
            return true;
        }

        return false;
    }
}
