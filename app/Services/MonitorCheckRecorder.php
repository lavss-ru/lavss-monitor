<?php

namespace App\Services;

use App\Models\MonitorCheck;
use Closure;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

class MonitorCheckRecorder
{
    /**
     * Observe only the checker callback, then insert after the monitoring transaction
     * has finished. Incident/notification exceptions never reclassify a measurement.
     * If the checker is skipped, nothing is recorded. Original errors propagate.
     */
    public function observe(string $type, int $id, string $origin, Closure $monitor): array
    {
        if (! in_array($type, MonitorCheck::TYPES, true) || ! in_array($origin, MonitorCheck::ORIGINS, true)) {
            throw new InvalidArgumentException('Invalid monitor type or check origin.');
        }
        $sample = null;
        $check = function (Closure $checker) use (&$sample): array {
            try {
                $result = $checker();
            } catch (Throwable $error) {
                $sample = ['status' => 'unknown', 'checked_at' => now(), 'response_ms' => null, 'http_status' => null];
                throw $error;
            }
            $sample = ['status' => $result['status'], 'checked_at' => now(),
                'response_ms' => $result['response_ms'] ?? null, 'http_status' => $result['http_status'] ?? null];
            return $result;
        };
        try {
            return $monitor($check);
        } finally {
            if ($sample !== null) {
                try {
                    // Also protects an outer caller transaction with a savepoint on PostgreSQL.
                    DB::transaction(fn () => $this->record($type, $id, $origin, $sample));
                } catch (Throwable $error) {
                    report($error);
                }
            }
        }
    }

    public function record(string $type, int $id, string $origin, array $sample): void
    {
        if (! in_array($type, MonitorCheck::TYPES, true) || ! in_array($origin, MonitorCheck::ORIGINS, true)
            || ! in_array($sample['status'], MonitorCheck::STATUSES, true) || $id < 1) {
            throw new InvalidArgumentException('Invalid monitor check sample.');
        }
        MonitorCheck::create([
            'monitor_type' => $type, 'monitor_id' => $id, 'origin' => $origin,
            'status' => $sample['status'], 'checked_at' => $sample['checked_at'],
            'response_ms' => $sample['status'] === 'unknown' ? null : $sample['response_ms'],
            'http_status' => $type === 'website' ? $sample['http_status'] : null,
        ]);
    }
}
