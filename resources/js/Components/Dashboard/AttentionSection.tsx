import React from 'react';
import { AlertTriangle, Clock, Activity, ExternalLink } from 'lucide-react';
import { AttentionItem, RecentEvent } from '@/types/dashboard';

interface AttentionSectionProps {
    items: AttentionItem[];
}

export const AttentionSection: React.FC<AttentionSectionProps> = ({ items }) => {
    if (!items || items.length === 0) {
        return null;
    }

    return (
        <div className="bg-amber-950/20 border border-amber-500/30 rounded-2xl p-5 mb-8 backdrop-blur-md">
            <div className="flex items-center space-x-2.5 mb-4 text-amber-400 font-semibold text-sm uppercase tracking-wider">
                <AlertTriangle className="w-5 h-5 text-amber-400 animate-pulse" />
                <span>Требуют внимания ({items.length})</span>
            </div>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                {items.map((item) => (
                    <div
                        key={item.id}
                        className="bg-slate-900/90 border border-amber-500/20 rounded-xl p-4 flex items-start space-x-3.5 hover:border-amber-500/40 transition-colors"
                    >
                        <div className="p-2 rounded-lg bg-amber-500/10 text-amber-400 shrink-0 mt-0.5">
                            <AlertTriangle className="w-4 h-4" />
                        </div>
                        <div className="flex-1 min-w-0">
                            <div className="flex items-center justify-between">
                                <h4 className="text-sm font-semibold text-slate-100 truncate">
                                    {item.title}
                                </h4>
                                <span className="text-xs px-2 py-0.5 rounded font-mono bg-amber-500/10 text-amber-300 border border-amber-500/20">
                                    {item.category}
                                </span>
                            </div>
                            <p className="text-xs text-slate-300 mt-1 leading-relaxed">
                                {item.description}
                            </p>
                        </div>
                    </div>
                ))}
            </div>
        </div>
    );
};

interface RecentEventsSectionProps {
    events: RecentEvent[];
}

export const RecentEventsSection: React.FC<RecentEventsSectionProps> = ({ events }) => {
    return (
        <div className="bg-slate-900/70 border border-slate-800 rounded-2xl p-5 mb-8 backdrop-blur-md">
            <div className="flex items-center justify-between mb-4 border-b border-slate-800/80 pb-3">
                <div className="flex items-center space-x-2.5 text-slate-200 font-semibold text-sm">
                    <Activity className="w-4 h-4 text-cyan-400" />
                    <span>Последние события</span>
                </div>
                <span className="text-xs text-slate-500 font-mono">Live log</span>
            </div>
            <div className="space-y-3">
                {events.map((evt) => (
                    <div
                        key={evt.id}
                        className="flex items-start justify-between p-3 rounded-xl bg-slate-950/60 border border-slate-800/60 hover:border-slate-700/80 transition-colors text-xs"
                    >
                        <div className="flex items-start space-x-3 min-w-0">
                            <div className={`w-2 h-2 rounded-full mt-1.5 shrink-0 ${
                                evt.type === 'success' ? 'bg-emerald-400' :
                                evt.type === 'warning' ? 'bg-amber-400' :
                                evt.type === 'error' ? 'bg-rose-500' : 'bg-cyan-400'
                            }`} />
                            <div className="min-w-0">
                                <span className="font-semibold text-slate-200 mr-2">{evt.title}</span>
                                <span className="text-slate-400">{evt.message}</span>
                            </div>
                        </div>
                        <div className="flex items-center space-x-1 text-slate-500 shrink-0 ml-3 font-mono">
                            <Clock className="w-3 h-3" />
                            <span>{evt.time}</span>
                        </div>
                    </div>
                ))}
            </div>
        </div>
    );
};
