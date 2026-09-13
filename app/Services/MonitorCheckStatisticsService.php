<?php

namespace App\Services;

use App\Models\MonitorCheck;
use InvalidArgumentException;

class MonitorCheckStatisticsService
{
    /** One SQL aggregation for all requested monitors and all three rolling windows. */
    public function forMonitors(string $type, array $ids): array
    {
        if (! in_array($type, MonitorCheck::TYPES, true)) {
            throw new InvalidArgumentException('Invalid monitor type.');
        }
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return [];
        }
        $now = now()->startOfSecond();
        $cutoffs = ['24h' => $now->copy()->subDay(), '7d' => $now->copy()->subDays(7), '30d' => $now->copy()->subDays(30)];
        $query = MonitorCheck::query()->select('monitor_id')->where('monitor_type', $type)
            ->whereIn('monitor_id', $ids)->where('origin', 'scheduled')
            ->where('checked_at', '>=', $cutoffs['30d'])->where('checked_at', '<=', $now)->groupBy('monitor_id');
        foreach (array_values($cutoffs) as $i => $cutoff) {
            foreach (MonitorCheck::STATUSES as $status) {
                $query->selectRaw("SUM(CASE WHEN checked_at >= ? AND status = ? THEN 1 ELSE 0 END) AS p{$i}_{$status}", [$cutoff, $status]);
            }
            $query->selectRaw("AVG(CASE WHEN checked_at >= ? AND status = 'online' THEN response_ms ELSE NULL END) AS p{$i}_average", [$cutoff]);
        }
        $rows = $query->get()->keyBy('monitor_id');
        $stats = [];
        foreach ($ids as $id) {
            $row = $rows->get($id);
            foreach (array_keys($cutoffs) as $i => $period) {
                $online = (int) ($row->{"p{$i}_online"} ?? 0);
                $offline = (int) ($row->{"p{$i}_offline"} ?? 0);
                $unknown = (int) ($row->{"p{$i}_unknown"} ?? 0);
                $measured = $online + $offline;
                $average = $row->{"p{$i}_average"} ?? null;
                $stats[$id][$period] = [
                    'uptime_percent' => $measured ? round(100 * $online / $measured, 2) : null,
                    'online_count' => $online, 'offline_count' => $offline, 'unknown_count' => $unknown,
                    'measured_count' => $measured, 'total_count' => $measured + $unknown,
                    'average_response_ms' => $average === null ? null : round((float) $average, 2),
                ];
            }
        }
        return $stats;
    }
}
