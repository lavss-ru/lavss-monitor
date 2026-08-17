import React from 'react';
import { Server, Globe, FileCode, Cpu } from 'lucide-react';
import { SummaryItem } from '@/types/dashboard';

interface SummaryCardsProps {
    summaries: {
        vps: SummaryItem;
        websites: SummaryItem;
        wordpress: SummaryItem;
        infrastructure: SummaryItem;
    };
}

export const SummaryCards: React.FC<SummaryCardsProps> = ({ summaries }) => {
    const cards = [
        {
            title: summaries.vps.label,
            count: summaries.vps.count,
            sub: summaries.vps.sub,
            icon: Server,
            color: 'from-blue-500/20 to-cyan-500/10 border-blue-500/30 text-blue-400',
            badgeBg: 'bg-blue-500/10 text-blue-400',
        },
        {
            title: summaries.websites.label,
            count: summaries.websites.count,
            sub: summaries.websites.sub,
            icon: Globe,
            color: 'from-cyan-500/20 to-emerald-500/10 border-cyan-500/30 text-cyan-400',
            badgeBg: 'bg-cyan-500/10 text-cyan-400',
        },
        {
            title: summaries.wordpress.label,
            count: summaries.wordpress.count,
            sub: summaries.wordpress.sub,
            icon: FileCode,
            color: 'from-emerald-500/20 to-teal-500/10 border-emerald-500/30 text-emerald-400',
            badgeBg: 'bg-emerald-500/10 text-emerald-400',
        },
        {
            title: summaries.infrastructure.label,
            count: summaries.infrastructure.count,
            sub: summaries.infrastructure.sub,
            icon: Cpu,
            color: 'from-purple-500/20 to-indigo-500/10 border-purple-500/30 text-purple-400',
            badgeBg: 'bg-purple-500/10 text-purple-400',
        },
    ];

    return (
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
            {cards.map((card, idx) => {
                const Icon = card.icon;
                return (
                    <div
                        key={idx}
                        className={`relative overflow-hidden bg-slate-900/70 backdrop-blur-md rounded-2xl border p-5 transition-all duration-200 hover:border-slate-700 hover:shadow-xl bg-gradient-to-br ${card.color}`}
                    >
                        <div className="flex items-center justify-between">
                            <span className="text-xs font-semibold uppercase tracking-wider text-slate-400">
                                {card.title}
                            </span>
                            <div className={`p-2.5 rounded-xl border border-current/20 ${card.badgeBg}`}>
                                <Icon className="w-5 h-5" />
                            </div>
                        </div>
                        <div className="mt-4 flex items-baseline justify-between">
                            <span className="text-3xl font-extrabold text-slate-100 font-mono tracking-tight">
                                {card.count}
                            </span>
                            <span className="text-xs text-slate-400 font-medium">
                                {card.sub}
                            </span>
                        </div>
                    </div>
                );
            })}
        </div>
    );
};
