import { Globe, ExternalLink } from 'lucide-react';
import { WebsiteItem } from '@/types/dashboard';

export function WebsitesSection({ websites }: { websites: WebsiteItem[] }) {
    return <div id="websites" className="bg-slate-900/70 border border-slate-800 rounded-2xl p-5 mb-8 backdrop-blur-md">
        <div className="flex justify-between items-center mb-4 border-b border-slate-800 pb-3"><h2 className="flex gap-2 items-center font-semibold"><Globe className="w-5 h-5 text-cyan-400" />Сайты & WordPress ({websites.length})</h2><a href="/websites" className="text-xs text-cyan-400">Управление сайтами</a></div>
        {websites.length === 0 ? <p className="text-sm text-slate-400">Нет включённых сайтов.</p> : <div className="overflow-x-auto"><table className="w-full text-left text-xs text-slate-300"><thead className="bg-slate-950/80 text-slate-400 uppercase font-mono"><tr>{['Сайт', 'Статус', 'HTTP', 'Отклик', 'Проверен', 'Тип'].map(title => <th key={title} className="px-4 py-3">{title}</th>)}</tr></thead><tbody className="divide-y divide-slate-800/60">{websites.map(site => <tr key={site.id}>
            <td className="px-4 py-3"><a href={site.url} target="_blank" rel="noreferrer" className="inline-flex gap-1 items-center hover:text-cyan-400">{site.name}<ExternalLink className="w-3 h-3" /></a></td>
            <td className={`px-4 py-3 font-mono ${site.status === 'online' ? 'text-emerald-400' : site.status === 'offline' ? 'text-rose-400' : 'text-slate-400'}`}>{site.status.toUpperCase()}</td>
            <td className="px-4 py-3 font-mono">{site.last_http_status ?? '—'}</td><td className="px-4 py-3 font-mono">{site.last_response_ms === null ? '—' : `${site.last_response_ms} ms`}</td><td className="px-4 py-3">{site.last_checked_at ? new Date(site.last_checked_at).toLocaleString('ru-RU') : 'никогда'}</td><td className="px-4 py-3">{site.type === 'wordpress' ? 'WordPress' : 'Website'}</td>
        </tr>)}</tbody></table></div>}
    </div>;
}
