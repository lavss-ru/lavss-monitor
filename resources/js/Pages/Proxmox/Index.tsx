import React, { useState } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import { Dialog, DialogPanel, DialogTitle } from '@headlessui/react';
import { Sidebar } from '@/Components/Dashboard/Sidebar';
import Inventory, { Node, Guest } from './Inventory';

interface Location { id: number; name: string; enabled: boolean }
export interface Connection {
    id: number; name: string; location_id: number; location: Location; host: string; port: number;
    scheme: 'http' | 'https'; verify_tls: boolean; api_user: string; api_token_id: string; enabled: boolean;
    status: string; last_checked_at: string | null; last_synced_at: string | null; last_response_ms: number | null;
    last_error_code: string | null; unavailable_reason: string | null; version: string | null; nodes: Node[]; guests: Guest[];
}
const button = 'rounded-lg border border-slate-700 px-3 py-2 text-sm hover:bg-slate-800 disabled:opacity-40';
const input = 'mt-1 w-full rounded-lg border-slate-700 bg-slate-950 text-slate-100';
const errors: Record<string, string> = {
    auth: 'Ошибка авторизации API', network: 'Ошибка подключения', http: 'Ошибка HTTP API',
    payload: 'Некорректный ответ API', internal: 'Внутренняя ошибка',
    disabled: 'Подключение отключено', location_unavailable: 'Площадка недоступна',
};
export const initialForm = (connection: Connection | undefined, locations: Location[]) => ({
    name: connection?.name ?? '', location_id: String(connection?.location_id ?? locations[0]?.id ?? ''),
    host: connection?.host ?? '', port: String(connection?.port ?? 8006), scheme: connection?.scheme ?? 'https',
    verify_tls: connection?.verify_tls ?? true, api_user: connection?.api_user ?? '', api_token_id: connection?.api_token_id ?? '',
    api_token_secret: '', enabled: connection?.enabled ?? true,
});

export function Editor({ connection, locations, close }: { connection?: Connection; locations: Location[]; close: () => void }) {
    // No useForm remember key: credentials must never enter browser history state.
    const form = useForm(initialForm(connection, locations));
    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: close, onFinish: () => form.setData('api_token_secret', '') };
        if (connection) form.put('/proxmox/' + connection.id, options);
        else form.post('/proxmox', options);
    };
    return <Dialog open onClose={() => !form.processing && close()} className="relative z-50">
        <div className="fixed inset-0 bg-slate-950/80" aria-hidden="true" />
        <div className="fixed inset-0 overflow-y-auto p-4"><div className="flex min-h-full items-center justify-center">
            <DialogPanel className="w-full max-w-lg rounded-2xl border border-slate-700 bg-slate-900 p-6 text-slate-100">
                <DialogTitle className="mb-4 text-xl">{connection ? 'Редактировать Proxmox' : 'Добавить Proxmox'}</DialogTitle>
                <form onSubmit={submit} className="space-y-3">
                    <label className="block">Название<input autoFocus required maxLength={255} className={input} value={form.data.name} onChange={e => form.setData('name', e.target.value)} /></label>
                    <label className="block">Площадка<select required className={input} value={form.data.location_id} onChange={e => form.setData('location_id', e.target.value)}><option value="" disabled>Выберите площадку</option>{locations.map(l => <option key={l.id} value={l.id}>{l.name}{!l.enabled && ' (отключена)'}</option>)}</select></label>
                    <label className="block">Host/IP<input required maxLength={253} className={input} value={form.data.host} onChange={e => form.setData('host', e.target.value)} /><span className="text-xs text-slate-400">Без scheme, порта и скобок IPv6</span></label>
                    <label className="block">Port<input required type="number" min={1} max={65535} className={input} value={form.data.port} onChange={e => form.setData('port', e.target.value)} /></label>
                    <label className="flex gap-2"><input type="checkbox" checked={form.data.scheme === 'https'} onChange={e => form.setData('scheme', e.target.checked ? 'https' : 'http')} />HTTPS</label>
                    <label className="flex gap-2"><input type="checkbox" checked={form.data.verify_tls} onChange={e => form.setData('verify_tls', e.target.checked)} />Проверять TLS сертификат</label>
                    {(form.data.scheme === 'http' || !form.data.verify_tls) && <p className="text-sm text-amber-300">Менее безопасный режим: {form.data.scheme === 'http' ? 'token передаётся без шифрования.' : 'подлинность сервера не проверяется.'}</p>}
                    <label className="block">API user (user@realm)<input required autoComplete="off" maxLength={255} className={input} value={form.data.api_user} onChange={e => form.setData('api_user', e.target.value)} /></label>
                    <label className="block">Token ID<input required autoComplete="off" maxLength={255} className={input} value={form.data.api_token_id} onChange={e => form.setData('api_token_id', e.target.value)} /></label>
                    <label className="block">Token secret<input type="password" autoComplete="new-password" required={!connection} maxLength={4096} className={input} value={form.data.api_token_secret} onChange={e => form.setData('api_token_secret', e.target.value)} />{connection && <span className="text-xs text-slate-400">Пустое поле сохраняет существующий secret.</span>}</label>
                    <label className="flex gap-2"><input type="checkbox" checked={form.data.enabled} onChange={e => form.setData('enabled', e.target.checked)} />Включено</label>
                    {Object.entries(form.errors).map(([key, value]) => <p key={key} role="alert" className="text-sm text-rose-300">{value}</p>)}
                    <div className="flex gap-2"><button className={button} disabled={form.processing}>Сохранить</button><button type="button" className={button} disabled={form.processing} onClick={close}>Отмена</button></div>
                </form>
            </DialogPanel>
        </div></div>
    </Dialog>;
}

export default function ProxmoxPage({ connections, locations, result }: {
    connections: Connection[]; locations: Location[]; result: { connection_id: number; success: boolean; message: string } | null;
}) {
    const [mobileOpen, setMobileOpen] = useState(false);
    const [editor, setEditor] = useState<Connection | 'new' | null>(null);
    const [busy, setBusy] = useState<number | null>(null);
    const action = (connection: Connection, kind: 'test' | 'sync' | 'delete') => {
        if (kind === 'delete' && !window.confirm('Удалить подключение и локальный inventory? Данные в Proxmox сохранятся.')) return;
        setBusy(connection.id);
        const options = { preserveScroll: true, onFinish: () => setBusy(null) };
        if (kind === 'delete') router.delete('/proxmox/' + connection.id, options);
        else router.post('/proxmox/' + connection.id + '/' + kind, {}, options);
    };
    return <div className="min-h-screen bg-slate-950 text-slate-100">
        <Head title="Proxmox" /><Sidebar mobileOpen={mobileOpen} setMobileOpen={setMobileOpen} />
        <main className="p-4 md:ml-64 md:p-8">
            <button className={button + ' md:hidden'} onClick={() => setMobileOpen(true)}>Меню</button>
            <header className="my-5 flex flex-wrap items-center justify-between gap-3"><div><h1 className="text-2xl font-bold">Proxmox</h1><p className="mt-1 text-sm text-slate-400">Nodes, VM и LXC · Только чтение · Автосинхронизация раз в минуту</p></div><button className={button} disabled={!locations.length} onClick={() => setEditor('new')}>Добавить Proxmox</button></header>
            {!locations.length && <p>Сначала добавьте площадку в <a href="/local-infrastructure" className="text-cyan-300">Локальной инфраструктуре</a>.</p>}
            {result && <p role="status" className={'mb-4 rounded-lg border p-3 ' + (result.success ? 'border-emerald-800' : 'border-amber-800')}>{connections.find(c => c.id === result.connection_id)?.name}: {result.message}</p>}
            {!connections.length && <p className="mt-6 text-slate-400">Подключений пока нет. Добавьте Proxmox с read-only API token.</p>}
            <div className="space-y-6">{connections.map(c => <article key={c.id} className="rounded-2xl border border-slate-800 bg-slate-900/50 p-4 md:p-6">
                <div className="flex flex-wrap justify-between gap-4"><div><h2 className="text-xl font-semibold">{c.name} · {c.status}</h2><p className="mt-1 text-slate-400">{c.location.name} · {c.scheme}://{c.host.includes(':') ? '[' + c.host + ']' : c.host}:{c.port}</p>
                    <p className={'mt-1 text-sm ' + (c.verify_tls && c.scheme === 'https' ? 'text-slate-400' : 'text-amber-300')}>{c.scheme === 'http' ? 'HTTP: token передаётся без шифрования' : c.verify_tls ? 'TLS: проверка включена' : 'TLS: проверка выключена — менее безопасно'}{c.version && ' · PVE ' + c.version}</p></div>
                    <div className="flex flex-wrap items-start gap-2"><button className={button} disabled={busy !== null || !!c.unavailable_reason} onClick={() => action(c, 'test')}>Проверить подключение</button><button className={button} disabled={busy !== null || !!c.unavailable_reason} onClick={() => action(c, 'sync')}>Синхронизировать</button><button className={button} disabled={busy !== null} onClick={() => setEditor(c)}>Изменить</button><button className={button} disabled={busy !== null} onClick={() => action(c, 'delete')}>Удалить</button></div>
                </div>
                {c.last_error_code && <p className="mt-3 text-sm text-amber-200">{errors[c.last_error_code] ?? errors.internal}</p>}
                <p className="my-4 text-xs text-slate-400">Проверка: {c.last_checked_at ? new Date(c.last_checked_at).toLocaleString() : '—'} · Отклик: {c.last_response_ms === null ? '—' : c.last_response_ms + ' ms'} · Snapshot: {c.last_synced_at ? new Date(c.last_synced_at).toLocaleString() : '—'}. Метрики на момент последней синхронизации; stale — отсутствует в последнем успешном ответе.</p>
                <Inventory nodes={c.nodes} guests={c.guests} available={c.status === 'online'} />
            </article>)}</div>
            {editor && <Editor connection={editor === 'new' ? undefined : editor} locations={locations} close={() => setEditor(null)} />}
        </main>
    </div>;
}
