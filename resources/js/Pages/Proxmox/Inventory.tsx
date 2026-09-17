import React from 'react';

export interface Metrics {
    cpu_usage: number | null; memory_used: number | null; memory_total: number | null;
    uptime_seconds: number | null; max_cpu: number | null;
}
export interface Incident {
    monitoring_enabled?: boolean; monitoring_state?: string; failure_started_at?: string | null;
    incident_confirmed_at?: string | null; last_seen_at?: string | null;
    availability?: Record<string, { uptime_percent: number | null }>;
}
export function MonitoringState({ monitor }: { monitor: Incident }) {
    const state = monitor.monitoring_state ?? 'ignored';
    return <span className={state === 'online' ? 'text-emerald-400' : state === 'offline' ? 'text-rose-400' : 'text-slate-400'}>
        {state === 'online' ? 'healthy' : state === 'offline' ? 'problem' : state === 'ignored' ? 'Мониторинг выключен' : 'unknown'}
        {monitor.incident_confirmed_at ? ' · confirmed' : monitor.failure_started_at ? ' · grace' : ''}
    </span>;
}
export function Availability({ monitor, label = 'Доступность' }: { monitor: Incident; label?: string }) {
    return <p className="text-xs text-slate-400">{label} 24ч / 7д / 30д: {['24h', '7d', '30d'].map(p => monitor.availability?.[p]?.uptime_percent == null ? '—' : monitor.availability[p].uptime_percent + '%').join(' / ')}</p>;
}
export interface Node extends Metrics, Incident { id: number; node_name: string; status: string; stale: boolean; last_seen_at: string | null }
export interface Guest extends Metrics, Incident {
    expected_status?: 'running' | 'stopped' | 'ignore';
    id: number; proxmox_node_id: number | null; guest_type: 'qemu' | 'lxc'; vmid: number; name: string | null;
    status: string; template: boolean; stale: boolean; disk_used: number | null; disk_total: number | null;
}
export const bytes = (value: number | null): string => {
    if (value === null) return '—';
    const unit = value > 0 ? Math.min(4, Math.floor(Math.log(value) / Math.log(1024))) : 0;
    return (value / 1024 ** unit).toFixed(unit ? 1 : 0) + ' ' + ['B', 'KiB', 'MiB', 'GiB', 'TiB'][unit];
};
export const cpu = (value: number | null) => value === null ? '—' : (value * 100).toFixed(1) + '%';
export const memory = (used: number | null, total: number | null) =>
    bytes(used) + ' / ' + bytes(total) + (used !== null && total ? ' (' + (used / total * 100).toFixed(1) + '%)' : '');
export const uptime = (seconds: number | null) => seconds === null ? '—' :
    Math.floor(seconds / 86400) + 'д ' + Math.floor(seconds % 86400 / 3600) + 'ч ' + Math.floor(seconds % 3600 / 60) + 'м';

export default function Inventory({ nodes, guests, available, edit, toggleNode }: { nodes: Node[]; guests: Guest[]; available: boolean; edit?: (guest: Guest) => void; toggleNode?: (node: Node) => void }) {
    const grouped = new Map<number | null, Guest[]>();
    const nodeIds = new Set(nodes.map(n => n.id));
    guests.forEach(guest => {
        const key = guest.proxmox_node_id !== null && nodeIds.has(guest.proxmox_node_id) ? guest.proxmox_node_id : null;
        const group = grouped.get(key) ?? [];
        group.push(guest); grouped.set(key, group);
    });
    const table = (rows: Guest[], nodeAvailable: boolean) => <div className="overflow-x-auto"><table className="w-full text-left text-sm">
        <thead className="text-slate-400"><tr>{['Тип / ID', 'Имя', 'Actual / Monitoring', 'CPU', 'RAM', 'Disk', 'Uptime'].map(h => <th key={h} className="p-3">{h}</th>)}</tr></thead>
        <tbody>{rows.map(g => <tr key={g.id} className={'border-t border-slate-800 ' + (g.stale ? 'text-slate-500' : '')}>
            <td className="p-3 whitespace-nowrap">{g.guest_type === 'qemu' ? 'VM' : 'LXC'} {g.vmid}</td>
            <td className="p-3">{g.name || '—'} {g.template && <span className="rounded bg-slate-800 px-2 text-xs">template</span>} {g.stale && <span>· stale</span>}</td>
            <td className="p-3">{nodeAvailable && !g.stale ? g.status : 'unknown'}<br /><MonitoringState monitor={{ ...g, monitoring_state: g.monitoring_state === 'ignored' || !g.monitoring_enabled ? 'ignored' : nodeAvailable && !g.stale ? g.monitoring_state : 'unknown' }} /><br /><span className="text-xs text-slate-400">Expected: {g.expected_status ?? 'running'} · Проверка: {g.last_seen_at ? new Date(g.last_seen_at).toLocaleString() : '—'}<br />Соответствие 24ч / 7д / 30д: {['24h', '7d', '30d'].map(p => g.availability?.[p]?.uptime_percent == null ? '—' : g.availability[p].uptime_percent + '%').join(' / ')}</span>{edit && <button className="block text-cyan-300 underline" onClick={() => edit(g)}>Настроить мониторинг</button>}</td>
            <td className="p-3">{cpu(g.cpu_usage)}</td><td className="p-3 whitespace-nowrap">{memory(g.memory_used, g.memory_total)}</td>
            <td className="p-3 whitespace-nowrap">{bytes(g.disk_used)} / {bytes(g.disk_total)}</td><td className="p-3 whitespace-nowrap">{uptime(g.uptime_seconds)}</td>
        </tr>)}</tbody>
    </table>{rows.length === 0 && <p className="p-3 text-sm text-slate-400">Нет VM/LXC</p>}</div>;
    return <div className="space-y-4">
        {nodes.length === 0 && <p className="text-slate-400">Inventory пока пуст. Выполните синхронизацию.</p>}
        {nodes.map(node => <section key={node.id} className={'rounded-xl border border-slate-800 ' + (node.stale ? 'opacity-60' : '')}>
            <div className="p-4"><h3 className="font-semibold">{node.node_name} · {available && !node.stale ? node.status : 'unknown'} {node.stale && '· stale'}</h3>
                <MonitoringState monitor={node} />{toggleNode && <button className="ml-3 text-sm text-cyan-300 underline" onClick={() => toggleNode(node)}>{node.monitoring_enabled ? 'Выключить мониторинг узла' : 'Включить мониторинг узла'}</button>}<Availability monitor={node} /><p className="mt-2 text-sm text-slate-400">CPU {cpu(node.cpu_usage)} · RAM {memory(node.memory_used, node.memory_total)} · Uptime {uptime(node.uptime_seconds)} · Проверка: {node.last_seen_at ? new Date(node.last_seen_at).toLocaleString() : '—'}</p>
            </div>{table(grouped.get(node.id) ?? [], available && !node.stale && node.status === 'online' && !node.incident_confirmed_at)}
        </section>)}
        {!!grouped.get(null)?.length && <section><h3>Без node</h3>{table(grouped.get(null)!, false)}</section>}
    </div>;
}
