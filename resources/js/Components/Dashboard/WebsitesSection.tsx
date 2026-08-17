import React from 'react';
import { Globe, ShieldCheck, ShieldAlert, ExternalLink, Zap } from 'lucide-react';
import { WebsiteItem } from '@/types/dashboard';

interface WebsitesSectionProps {
    websites: WebsiteItem[];
}

export const WebsitesSection: React.FC<WebsitesSectionProps> = ({ websites }) => {
    return (
        <div id="websites" className="bg-slate-900/70 border border-slate-800 rounded-2xl p-5 mb-8 backdrop-blur-md">
            <div className="flex items-center justify-between mb-4 border-b border-slate-800 pb-3">
                <div className="flex items-center space-x-2 text-slate-200 font-semibold text-base">
                    <Globe className="w-5 h-5 text-cyan-400" />
                    <span>Сайты & WordPress ({websites.length})</span>
                </div>
                <span className="text-xs text-slate-400 font-mono">HTTP/HTTPS Probes</span>
            </div>

            <div className="overflow-x-auto">
                <table className="w-full text-left text-xs text-slate-300">
                    <thead className="bg-slate-950/80 text-slate-400 uppercase font-mono text-[11px]">
                        <tr>
                            <th className="px-4 py-3 rounded-l-lg">Сайт</th>
                            <th className="px-4 py-3">Статус</th>
                            <th className="px-4 py-3">Отклик</th>
                            <th className="px-4 py-3">SSL Сертификат</th>
                            <th className="px-4 py-3 rounded-r-lg text-right">Тип</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-800/60">
                        {websites.map((site) => (
                            <tr key={site.id} className="hover:bg-slate-950/40 transition-colors">
                                <td className="px-4 py-3 font-medium text-slate-100">
                                    <a
                                        href={site.url}
                                        target="_blank"
                                        rel="noreferrer"
                                        className="flex items-center space-x-1.5 hover:text-cyan-400 transition-colors"
                                    >
                                        <span>{site.name}</span>
                                        <ExternalLink className="w-3 h-3 text-slate-500" />
                                    </a>
                                </td>
                                <td className="px-4 py-3">
                                    <span className="inline-flex items-center px-2 py-0.5 rounded font-mono text-xs bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                                        <span className="w-1.5 h-1.5 rounded-full bg-emerald-400 mr-1.5" />
                                        {site.status}
                                    </span>
                                </td>
                                <td className="px-4 py-3 font-mono text-slate-300">
                                    <span className="inline-flex items-center">
                                        <Zap className="w-3 h-3 text-amber-400 mr-1" />
                                        {site.responseTime}
                                    </span>
                                </td>
                                <td className="px-4 py-3">
                                    {site.sslDays <= 7 ? (
                                        <span className="inline-flex items-center px-2 py-0.5 rounded font-mono text-xs bg-amber-500/10 text-amber-400 border border-amber-500/30">
                                            <ShieldAlert className="w-3 h-3 mr-1 text-amber-400" />
                                            Истекает через {site.sslDays} дн.
                                        </span>
                                    ) : (
                                        <span className="inline-flex items-center px-2 py-0.5 rounded font-mono text-xs bg-slate-800 text-slate-300">
                                            <ShieldCheck className="w-3 h-3 mr-1 text-emerald-400" />
                                            Действителен ({site.sslDays} дн.)
                                        </span>
                                    )}
                                </td>
                                <td className="px-4 py-3 text-right font-mono text-slate-400">
                                    {site.type}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
};
