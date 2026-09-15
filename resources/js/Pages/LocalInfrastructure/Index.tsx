import MonitorUptime from '@/Components/MonitorUptime';
import { MonitorStatsMap } from '@/types/monitorChecks';
import React, { useEffect, useState } from 'react';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Dialog, DialogPanel, DialogTitle } from '@headlessui/react';
import { Sidebar } from '@/Components/Dashboard/Sidebar';
import { Header } from '@/Components/Dashboard/Header';
import { startLocationsPolling } from './locationsPolling';
import WireGuardDiagnostics from './WireGuardDiagnostics';

interface Device {
    location_unavailable: boolean;
    id: number; location_id: number; name: string; type: string; host: string; check_port: number;
    enabled: boolean; description: string | null; status: 'unknown' | 'online' | 'offline';
    last_checked_at: string | null; last_response_ms: number | null; incident_confirmed_at: string | null;
}
interface Location {
    id: number; name: string; description: string | null; connection_type: string; enabled: boolean; devices: Device[];
    monitoring_enabled: boolean; probe_type: 'tcp'; probe_host: string | null; probe_port: number | null;
    status: 'online' | 'offline' | 'unknown'; last_checked_at: string | null; last_response_ms: number | null;
    incident_confirmed_at: string | null; wireguard_interface: string | null; wireguard_peer_public_key: string | null;
    wireguard_diagnostic_state: string; wireguard_last_handshake_at: string | null;
    wireguard_rx_bytes: number | null; wireguard_tx_bytes: number | null;
}
interface Props { locationStats: MonitorStatsMap; localDeviceStats: MonitorStatsMap; locations: Location[]; connectionTypes: string[]; deviceTypes: string[] }
const connections: Record<string, string> = { local: 'Локальная сеть', wireguard: 'WireGuard', vpn: 'VPN', other: 'Другое' };
const types: Record<string, string> = { proxmox: 'Proxmox', linux_server: 'Linux сервер', windows_server: 'Windows сервер', router: 'Роутер', vm: 'Виртуальная машина', network_device: 'Сетевое устройство', other: 'Другое' };
const button = 'rounded-lg border border-slate-700 px-3 py-2 text-sm hover:bg-slate-800 disabled:opacity-40 disabled:cursor-not-allowed';
const input = 'mt-1 w-full rounded-lg border-slate-700 bg-slate-950 text-slate-100';

function Editor({ kind, location, device, initialLocationId, locations, connectionTypes, deviceTypes, close }: Props & {
    kind: 'location' | 'device'; location?: Location; device?: Device; initialLocationId?: number; close: () => void;
}) {
    const form = useForm({
        name: device?.name ?? location?.name ?? '', description: device?.description ?? location?.description ?? '',
        enabled: device?.enabled ?? location?.enabled ?? true, connection_type: location?.connection_type ?? 'local',
        monitoring_enabled: location?.monitoring_enabled ?? false, probe_type: 'tcp',
        probe_host: location?.probe_host ?? '', probe_port: String(location?.probe_port ?? ''),
        wireguard_interface: location?.wireguard_interface ?? '', wireguard_peer_public_key: location?.wireguard_peer_public_key ?? '',
        location_id: String(device?.location_id ?? initialLocationId ?? locations[0]?.id ?? ''),
        type: device?.type ?? 'other', host: device?.host ?? '', check_port: String(device?.check_port ?? 22),
    });
    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        const id = kind === 'location' ? location?.id : device?.id;
        const path = kind === 'location' ? '/locations' : '/local-devices';
        if (id) form.put(`${path}/${id}`, { onSuccess: close, preserveScroll: true });
        else form.post(path, { onSuccess: close, preserveScroll: true });
    };
    return <Dialog open onClose={() => !form.processing && close()} className="relative z-50">
        <div className="fixed inset-0 bg-slate-950/80" aria-hidden="true" />
        <div className="fixed inset-0 overflow-y-auto p-4"><div className="flex min-h-full items-center justify-center">
            <DialogPanel className="w-full max-w-lg rounded-2xl border border-slate-700 bg-slate-900 p-6 text-slate-100">
                <DialogTitle className="mb-5 text-lg font-bold">{device || location ? 'Редактировать' : 'Добавить'} {kind === 'location' ? 'площадку' : 'устройство'}</DialogTitle>
                <form onSubmit={submit} className="space-y-4">
                    <label className="block text-sm">Название<input autoFocus required maxLength={255} className={input} value={form.data.name} onChange={e => form.setData('name', e.target.value)} /></label>
                    {kind === 'location' ? <label className="block text-sm">Тип подключения<select className={input} value={form.data.connection_type} onChange={e => form.setData('connection_type', e.target.value)}>{connectionTypes.map(t => <option key={t} value={t}>{connections[t]}</option>)}</select></label> : <>
                        <label className="block text-sm">Площадка<select required className={input} value={form.data.location_id} onChange={e => form.setData('location_id', e.target.value)}>{locations.map(l => <option key={l.id} value={l.id}>{l.name}{!l.enabled ? ' (отключена)' : ''}</option>)}</select></label>
                        <label className="block text-sm">Тип устройства<select className={input} value={form.data.type} onChange={e => form.setData('type', e.target.value)}>{deviceTypes.map(t => <option key={t} value={t}>{types[t]}</option>)}</select></label>
                        <label className="block text-sm">IP или hostname<input required maxLength={253} className={input} placeholder="pve.internal" value={form.data.host} onChange={e => form.setData('host', e.target.value)} /><span className="text-xs text-slate-400">Без протокола, порта и скобок IPv6</span></label>
                        <label className="block text-sm">TCP порт<input required type="number" min={1} max={65535} className={input} value={form.data.check_port} onChange={e => form.setData('check_port', e.target.value)} /></label>
                    </>}
                    {kind === 'location' && <>
                        <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={form.data.monitoring_enabled} onChange={e => form.setData('monitoring_enabled', e.target.checked)} />Проверять доступность площадки по TCP</label>
                        <p className="text-xs text-slate-400">Выберите постоянно доступный TCP сервис внутри площадки. При подтверждённом сбое проверки устройств приостанавливаются.</p>
                        <label className="block text-sm">Контрольный IP или hostname<input className={input} required={form.data.monitoring_enabled} maxLength={253} value={form.data.probe_host} onChange={e => form.setData('probe_host', e.target.value)} /></label>
                        <label className="block text-sm">Контрольный TCP порт<input className={input} type="number" min={1} max={65535} required={form.data.monitoring_enabled || !!form.data.probe_host} value={form.data.probe_port} onChange={e => form.setData('probe_port', e.target.value)} /></label>
                        {form.data.connection_type === 'wireguard' && <>
                            <label className="block text-sm">WG интерфейс (необязательно)<input className={input} maxLength={15} pattern="[A-Za-z0-9_.-]{1,15}" value={form.data.wireguard_interface} onChange={e => form.setData('wireguard_interface', e.target.value)} /></label>
                            <label className="block text-sm">Публичный ключ peer (необязательно)<input className={input} maxLength={44} value={form.data.wireguard_peer_public_key} onChange={e => form.setData('wireguard_peer_public_key', e.target.value)} /></label>
                            <p className="text-xs text-slate-400">Только диагностика существующего интерфейса. Если peers несколько, укажите публичный ключ нужного peer.</p>
                        </>}
                    </>}
                    <label className="block text-sm">Описание<textarea maxLength={5000} className={input} value={form.data.description} onChange={e => form.setData('description', e.target.value)} /></label>
                    <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={form.data.enabled} onChange={e => form.setData('enabled', e.target.checked)} />Мониторинг включён</label>
                    {kind === 'location' && <p className="text-xs text-slate-400">Отключение площадки приостанавливает мониторинг всех её устройств.</p>}
                    {Object.entries(form.errors).map(([field, error]) => <p role="alert" className="text-sm text-rose-400" key={field}>{error}</p>)}
                    <div className="flex gap-3"><button disabled={form.processing} className={`${button} bg-blue-600`} type="submit">{form.processing ? 'Сохранение…' : 'Сохранить'}</button><button disabled={form.processing} type="button" className={button} onClick={close}>Отмена</button></div>
                </form>
            </DialogPanel>
        </div></div>
    </Dialog>;
}

export default function Index(props: Props) {
    useEffect(startLocationsPolling, []);

    const { locations } = props;
    const page = usePage();
    const [mobileOpen, setMobileOpen] = useState(false);
    const [editor, setEditor] = useState<{ kind: 'location' | 'device'; location?: Location; device?: Device; initialLocationId?: number } | null>(null);
    const [busy, setBusy] = useState(false);
    const check = (path: string) => { setBusy(true); router.post(path, {}, { preserveScroll: true, onFinish: () => setBusy(false) }); };
    const remove = (path: string, name: string) => {
        if (!window.confirm(`Удалить «${name}»? Это действие нельзя отменить.`)) return;
        setBusy(true); router.delete(path, { preserveScroll: true, onFinish: () => setBusy(false) });
    };
    const toggle = (device: Device) => {
        setBusy(true);
        router.put(`/local-devices/${device.id}`, { name: device.name, location_id: device.location_id, type: device.type, host: device.host, check_port: device.check_port, description: device.description, enabled: !device.enabled }, { preserveScroll: true, onFinish: () => setBusy(false) });
    };
    return <div className="min-h-screen bg-slate-950 text-slate-100">
        <Head title="Локальная инфраструктура" />
        <Sidebar mobileOpen={mobileOpen} setMobileOpen={setMobileOpen} onAddObjectClick={() => setEditor({ kind: locations.length ? 'device' : 'location' })} />
        <div className="md:pl-64">
            <Header statusTitle="Локальная инфраструктура" overallStatus="ok" userName={page.props.auth.user.name} onMenuToggle={() => setMobileOpen(true)} />
            <main className="mx-auto max-w-7xl space-y-6 p-4 sm:p-8">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div><h1 className="text-2xl font-bold">Локальная инфраструктура</h1><p className="mt-2 max-w-xl text-sm text-slate-400">TCP доступность площадок и устройств в LAN и через VPN. Задержки подтверждения задаются в настройках уведомлений. При недоступности площадки отдельные сбои устройств не создаются.</p></div>
                    <div className="flex flex-wrap gap-2"><button className={button} onClick={() => setEditor({ kind: 'location' })}>Добавить площадку</button><button className={button} disabled={!locations.length} onClick={() => setEditor({ kind: 'device' })}>Добавить устройство</button><button className={button} disabled={busy || !locations.some(l => l.enabled && (l.monitoring_enabled || l.devices.some(d => d.enabled)))} onClick={() => check('/local-devices/check-all')}>{busy ? 'Выполняется…' : 'Проверить включённые'}</button></div>
                </div>
                {!editor && Object.entries(page.props.errors).map(([key, message]) => <p key={key} role="alert" className="text-rose-400">{message}</p>)}
                {!locations.length && <div className="rounded-2xl border border-dashed border-slate-700 p-10 text-center text-slate-400">Добавьте площадку, затем устройства для мониторинга.</div>}
                {locations.map(location => <section key={location.id} className="overflow-hidden rounded-2xl border border-slate-800 bg-slate-900/50">
                    <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-800 p-5">
                        <div><h2 className="text-lg font-bold">{location.name} <span className={`ml-2 text-sm ${location.status === 'online' ? 'text-emerald-400' : location.status === 'offline' ? 'text-rose-400' : 'text-slate-400'}`}>{location.status.toUpperCase()}</span></h2>
                            <p className="mt-1 text-xs text-slate-400">{location.monitoring_enabled ? `TCP: ${location.probe_host ?? '—'}:${location.probe_port ?? '—'}` : 'Доступность площадки не контролируется'}{location.status === 'offline' && (location.incident_confirmed_at ? ' · Сбой подтверждён' : ' · Ожидание подтверждения')}</p>
                            <p className="mt-1 text-xs text-slate-400">Проверка: {location.last_checked_at ? new Date(location.last_checked_at).toLocaleString('ru-RU') : 'ещё не выполнялась'} · Отклик: {location.last_response_ms === null ? '—' : `${location.last_response_ms} ms`}</p>
                            <MonitorUptime stats={props.locationStats[location.id]} />
                            <WireGuardDiagnostics {...location} /><p className="text-sm text-cyan-400">{connections[location.connection_type]}{!location.enabled && ' · Мониторинг приостановлен'}</p>{location.description && <p className="mt-1 whitespace-pre-wrap text-sm text-slate-400">{location.description}</p>}</div>
                        <div className="flex flex-wrap gap-2"><button className={button} disabled={busy || !location.enabled || !location.monitoring_enabled} onClick={() => check(`/locations/${location.id}/check`)}>Проверить площадку</button><button className={button} onClick={() => setEditor({ kind: 'device', initialLocationId: location.id })}>Добавить устройство</button><button className={button} onClick={() => setEditor({ kind: 'location', location })}>Изменить площадку</button><button className={button} disabled={busy || !!location.devices.length} title={location.devices.length ? 'Сначала удалите или перенесите устройства' : 'Удалить пустую площадку'} onClick={() => remove(`/locations/${location.id}`, location.name)}>Удалить площадку</button></div>
                    </div>
                    {!location.devices.length ? <p className="p-5 text-sm text-slate-400">В этой площадке пока нет устройств.</p> : <div className="divide-y divide-slate-800">{location.devices.map(device => <article key={device.id} className="flex flex-wrap items-center justify-between gap-4 p-5">
                        <div className="min-w-0"><h3 className="font-semibold">{device.name} <span className="ml-2 text-xs font-normal text-slate-400">{types[device.type]}</span></h3><p className="break-all font-mono text-sm text-slate-400">{device.host.includes(':') ? `[${device.host}]` : device.host}:{device.check_port}</p>
                            <MonitorUptime stats={props.localDeviceStats[device.id]} />
                            {device.description && <p className="mt-1 whitespace-pre-wrap text-sm text-slate-400">{device.description}</p>}
                            <div className="mt-2 flex flex-wrap gap-3 text-xs"><span className={device.status === 'online' ? 'text-emerald-400' : device.status === 'offline' ? 'text-rose-400' : 'text-slate-400'}>{device.status.toUpperCase()}</span>{device.location_unavailable && <span className="text-amber-400">{location.status === 'offline' ? 'Площадка недоступна' : 'Доступность площадки неизвестна'}</span>}<span>{device.enabled ? 'Включено' : 'Отключено'}{!location.enabled && ' · Площадка отключена'}</span>{device.status === 'offline' && device.enabled && location.enabled && <span className="text-amber-400">{device.incident_confirmed_at ? 'Сбой подтверждён' : 'Ожидание подтверждения'}</span>}<span className="text-slate-400">Проверка: {device.last_checked_at ? new Date(device.last_checked_at).toLocaleString('ru-RU') : 'ещё не выполнялась'}</span><span className="text-slate-400">Отклик: {device.last_response_ms === null ? '—' : `${device.last_response_ms} ms`}</span></div>
                        </div>
                        <div className="flex flex-wrap gap-2"><button className={button} disabled={busy} onClick={() => check(`/local-devices/${device.id}/check`)}>{device.enabled && location.enabled ? 'Проверить' : 'Диагностика'}</button><button className={button} disabled={busy} onClick={() => toggle(device)}>{device.enabled ? 'Отключить' : 'Включить'}</button><button className={button} onClick={() => setEditor({ kind: 'device', device })}>Изменить</button><button className={button} disabled={busy} onClick={() => remove(`/local-devices/${device.id}`, device.name)}>Удалить</button></div>
                    </article>)}</div>}
                </section>)}
            </main>
        </div>
        {editor && <Editor {...props} {...editor} close={() => setEditor(null)} />}
    </div>;
}
