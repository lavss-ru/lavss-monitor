export interface MonitorPeriodStats {
    uptime_percent: number | null;
    online_count: number;
    offline_count: number;
    unknown_count: number;
    measured_count: number;
    total_count: number;
    average_response_ms: number | null;
}
export type MonitorUptimeStats = Record<'24h' | '7d' | '30d', MonitorPeriodStats>;
export type MonitorStatsMap = Record<number, MonitorUptimeStats>;
