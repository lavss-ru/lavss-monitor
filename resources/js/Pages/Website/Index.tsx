import { useState } from 'react';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Globe, PlusCircle, RefreshCw, Pencil, Trash2 } from 'lucide-react';
import Modal from '@/Components/Modal';
import { Sidebar } from '@/Components/Dashboard/Sidebar';
import { Header } from '@/Components/Dashboard/Header';
import { WebsiteRecord } from '@/types/website';

const inputClass = 'w-full rounded-xl bg-slate-950 border-slate-700 text-slate-100 focus:border-cyan-500 focus:ring-cyan-500';
const buttonClass = 'inline-flex items-center justify-center gap-2 rounded-xl border border-slate-700 px-4 py-2 text-sm text-slate-200 hover:bg-slate-800 disabled:opacity-40 disabled:cursor-not-allowed';

function WebsiteForm({ site, onClose }: { site: WebsiteRecord | null; onClose: () => void }) {
    const form = useForm({ name: site?.name ?? '', url: site?.url ?? '', type: site?.type ?? 'website', enabled: site?.enabled ?? true, description: site?.description ?? '' });
    return <form className="space-y-4 bg-slate-900 p-6 text-slate-100" onSubmit={event => {
        event.preventDefault();
        const options = { onSuccess: onClose, preserveScroll: true };
        if (site) form.put(`/websites/${site.id}`, options);
        else form.post('/websites', options);
    }}>
        <h2 className="text-lg font-bold">{site ? 'Редактировать сайт' : 'Добавить сайт'}</h2>
        <div><label htmlFor="website-name">Название *</label><input id="website-name" className={inputClass} required maxLength={255} value={form.data.name} onChange={e => form.setData('name', e.target.value)} />{form.errors.name && <p role="alert" className="text-rose-400 text-sm">{form.errors.name}</p>}</div>
        <div><label htmlFor="website-url">URL *</label><input id="website-url" type="url" className={inputClass} required maxLength={255} placeholder="https://example.com" value={form.data.url} onChange={e => form.setData('url', e.target.value)} />{form.errors.url && <p role="alert" className="text-rose-400 text-sm">{form.errors.url}</p>}</div>
        <div><label htmlFor="website-type">Тип</label><select id="website-type" className={inputClass} value={form.data.type} onChange={e => form.setData('type', e.target.value as WebsiteRecord['type'])}><option value="website">Website</option><option value="wordpress">WordPress</option></select>{form.errors.type && <p role="alert" className="text-rose-400 text-sm">{form.errors.type}</p>}</div>
        <div><label htmlFor="website-description">Описание</label><textarea id="website-description" className={inputClass} rows={3} value={form.data.description} onChange={e => form.setData('description', e.target.value)} />{form.errors.description && <p role="alert" className="text-rose-400 text-sm">{form.errors.description}</p>}</div>
        <label className="flex items-center gap-3"><input type="checkbox" checked={form.data.enabled} onChange={e => form.setData('enabled', e.target.checked)} />Включить мониторинг</label>
        {form.errors.enabled && <p role="alert" className="text-rose-400 text-sm">{form.errors.enabled}</p>}
        <div className="flex gap-3"><button className={`${buttonClass} bg-blue-600`} disabled={form.processing}>{form.processing ? 'Сохранение…' : 'Сохранить'}</button><button type="button" className={buttonClass} disabled={form.processing} onClick={onClose}>Отмена</button></div>
    </form>;
}

export default function WebsiteIndex({ websiteList }: { websiteList: WebsiteRecord[] }) {
    const user = usePage().props.auth.user;
    const [mobileOpen, setMobileOpen] = useState(false);
    const [editing, setEditing] = useState<WebsiteRecord | null | undefined>(undefined);
    const [deleting, setDeleting] = useState<WebsiteRecord | null>(null);
    const [busyDelete, setBusyDelete] = useState(false);
    const [checking, setChecking] = useState<number | 'all' | null>(null);
    const [actionError, setActionError] = useState('');
    const offline = websiteList.filter(site => site.enabled && site.status === 'offline').length;
    const check = (id: number | 'all') => {
        setChecking(id); setActionError('');
        router.post(id === 'all' ? '/websites/check-all' : `/websites/${id}/check`, {}, {
            preserveScroll: true,
            onError: () => setActionError('Не удалось выполнить проверку.'),
            onFinish: () => setChecking(null),
        });
    };
    return <div className="min-h-screen bg-slate-950 text-slate-100 flex flex-col font-sans">
        <Head title="Сайты — lavss monitor" />
        <Sidebar mobileOpen={mobileOpen} setMobileOpen={setMobileOpen} onAddObjectClick={() => setEditing(null)} />
        <div className="md:pl-64 flex flex-col flex-1 min-w-0">
            <Header statusTitle={offline ? `🟡 Требуют внимания — ${offline}` : 'Сайты — HTTP/HTTPS мониторинг'} overallStatus={offline ? 'warning' : 'ok'} userName={user.name} onMenuToggle={() => setMobileOpen(true)} />
            <main className="p-4 sm:p-6 md:p-8 max-w-7xl w-full mx-auto">
                <div className="flex flex-wrap justify-between gap-4 mb-6"><div><h2 className="text-xl font-bold flex gap-2 items-center"><Globe className="text-cyan-400" />Сайты ({websiteList.length})</h2><p className="text-sm text-slate-400 mt-1">Ручная проверка HTTP/HTTPS</p></div><div className="flex gap-3"><button className={buttonClass} disabled={checking !== null || !websiteList.some(site => site.enabled)} onClick={() => check('all')}><RefreshCw className={`w-4 h-4 ${checking === 'all' ? 'animate-spin' : ''}`} />{checking === 'all' ? 'Проверка…' : 'Проверить все'}</button><button className={`${buttonClass} bg-blue-600`} onClick={() => setEditing(null)}><PlusCircle className="w-4 h-4" />Добавить сайт</button></div></div>
                {actionError && <p role="alert" className="text-rose-400 mb-4">{actionError}</p>}
                {websiteList.length === 0 ? <div className="text-center py-24"><Globe className="mx-auto w-12 h-12 text-slate-500 mb-4" /><h3 className="text-lg font-semibold">Нет сайтов</h3><p className="text-slate-400 mt-2">Добавьте первый сайт, чтобы проверить его доступность.</p></div> : <div className="space-y-3">{websiteList.map(site => <article key={site.id} className={`rounded-2xl border p-5 bg-slate-900/70 ${site.enabled && site.status === 'offline' ? 'border-rose-500/30' : 'border-slate-800'}`}>
                    <div className="flex flex-wrap items-center justify-between gap-4"><div className="min-w-0"><div className="flex flex-wrap gap-3 items-center"><h3 className="font-bold break-all">{site.name}</h3><span className={`text-xs font-mono rounded-lg px-2 py-1 ${site.status === 'online' ? 'bg-emerald-500/10 text-emerald-400' : site.status === 'offline' ? 'bg-rose-500/10 text-rose-400' : 'bg-slate-800 text-slate-400'}`}>{site.status.toUpperCase()}</span><span className="text-xs text-slate-400">{site.type === 'wordpress' ? 'WordPress' : 'Website'} · {site.enabled ? 'Включён' : 'Отключён'}</span></div><a href={site.url} target="_blank" rel="noreferrer" className="text-cyan-400 text-sm break-all">{site.url}</a></div><div className="flex gap-2"><button className={buttonClass} disabled={checking !== null} onClick={() => check(site.id)}><RefreshCw className={`w-4 h-4 ${checking === site.id || (checking === 'all' && site.enabled) ? 'animate-spin' : ''}`} />Проверить</button><button className={buttonClass} aria-label={`Редактировать ${site.name}`} onClick={() => setEditing(site)}><Pencil className="w-4 h-4" /></button><button className={buttonClass} aria-label={`Удалить ${site.name}`} onClick={() => setDeleting(site)}><Trash2 className="w-4 h-4" /></button></div></div>
                    <div className="flex flex-wrap gap-4 mt-3 text-xs font-mono text-slate-400"><span>HTTP: {site.last_http_status ?? '—'}</span><span>Отклик: {site.last_response_ms === null ? '—' : `${site.last_response_ms} ms`}</span><span>Проверен: {site.last_checked_at ? new Date(site.last_checked_at).toLocaleString('ru-RU') : 'никогда'}</span></div>{site.description && <p className="text-sm text-slate-400 mt-3 break-words">{site.description}</p>}
                </article>)}</div>}
            </main>
        </div>
        <Modal show={editing !== undefined} onClose={() => setEditing(undefined)}>{editing !== undefined && <WebsiteForm key={editing?.id ?? 'new'} site={editing} onClose={() => setEditing(undefined)} />}</Modal>
        <Modal show={deleting !== null} closeable={!busyDelete} onClose={() => setDeleting(null)}><div className="bg-slate-900 text-slate-100 p-6"><h2 className="text-lg font-bold">Удалить сайт?</h2><p className="my-4 break-all">{deleting?.name} — данные сайта будут удалены.</p><div className="flex gap-3"><button className={`${buttonClass} bg-rose-600`} disabled={busyDelete} onClick={() => {
            if (!deleting) return;
            setBusyDelete(true); setActionError('');
            router.delete(`/websites/${deleting.id}`, { preserveScroll: true, onSuccess: () => setDeleting(null), onError: () => setActionError('Не удалось удалить сайт.'), onFinish: () => setBusyDelete(false) });
        }}>{busyDelete ? 'Удаление…' : 'Удалить'}</button><button className={buttonClass} disabled={busyDelete} onClick={() => setDeleting(null)}>Отмена</button></div></div></Modal>
    </div>;
}
