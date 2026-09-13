import { MonitorUptimeStats } from '@/types/monitorChecks';

const periods = [['24h', '24ч'], ['7d', '7д'], ['30d', '30д']] as const;

export default function MonitorUptime({ stats }: { stats?: MonitorUptimeStats }) {
    return <div className="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-400">
        <span>Uptime:</span>
        {periods.map(([key, label]) => {
            const period = stats?.[key];
            const percent = period?.uptime_percent;
            const detail = `По имеющимся автоматическим проверкам: ${period?.measured_count ?? 0} измерений; UNKNOWN: ${period?.unknown_count ?? 0}. Период не означает наличие полной истории за все дни.`;
            return <span key={key} tabIndex={0} title={detail} aria-label={`${label}: ${percent == null ? 'нет данных' : `${percent}%`}. ${detail}`}>
                {label} {percent == null ? '—' : `${Number(percent.toFixed(2))}%`}
            </span>;
        })}
        <span className="text-slate-500">по имеющимся автопроверкам</span>
    </div>;
}
