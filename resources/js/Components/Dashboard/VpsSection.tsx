import React from 'react';
import { Server, Wifi, WifiOff, HelpCircle, Clock, Zap, Link } from 'lucide-react';
import { VPSItem } from '@/types/dashboard';

interface VpsSectionProps {
    servers: VPSItem[];
}

const StatusBadge: React.FC<{ status: VPSItem['status'] }> = ({ status }) => {
    if (status === 'online') {
        return (
            <span className="inline-flex items-center gap-1.5 px-2 py-0.5 rounded text-xs font-mono font-medium bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                <Wifi className="w-3 h-3" /> ONLINE
            </span>
        );
    }
    if (status === 'offline') {
        return (
            <span className="inline-flex items-center gap-1.5 px-2 py-0.5 rounded text-xs font-mono font-medium bg-rose-500/10 text-rose-400 border border-rose-500/20">
                <WifiOff className="w-3 h-3" /> OFFLINE
            </span>
        );
    }
    return (
        <span className="inline-flex items-center gap-1.5 px-2 py-0.5 rounded text-xs font-mono font-medium bg-slate-700/50 text-slate-400 border border-slate-700">
            <HelpCircle className="w-3 h-3" /> UNKNOWN
        </span>
    );
};

export const VpsSection: React.FC<VpsSectionProps> = ({ servers }) => {
    if (servers.length === 0) {
        return null;
    }

    return (
        <div id="vps" className="bg-slate-900/70 border border-slate-800 rounded-2xl p-5 mb-8 backdrop-blur-md">
            <div className="flex items-center justify-between mb-4 border-b border-slate-800 pb-3">
                <div className="flex items-center space-x-2 text-slate-200 font-semibold text-base">
                    <Server className="w-5 h-5 text-blue-400" />
                    <span>VPS / Серверы ({servers.length})</span>
                </div>
                <a
                    href="/vps"
                    className="text-xs text-cyan-400 hover:text-cyan-300 font-mono transition-colors"
                >
                    Управление →
                </a>
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
                {servers.map((srv) => (
                    <div
                        key={srv.id}
                        className={`bg-slate-950/80 border rounded-xl p-4 flex flex-col justify-between transition-all ${
                            srv.status === 'offline'
                                ? 'border-rose-500/40 shadow-lg shadow-rose-500/5'
                                : srv.status === 'online'
                                ? 'border-emerald-500/20 hover:border-emerald-500/40'
                                : 'border-slate-800 hover:border-slate-700'
                        }`}
                    >
                        <div>
                            <div className="flex items-center justify-between mb-2">
                                <h3 className="font-bold text-slate-100 text-base truncate mr-2">{srv.name}</h3>
                                <StatusBadge status={srv.status} />
                            </div>
                            <div className="text-xs font-mono text-slate-400 mb-1">{srv.ip}</div>
                            {srv.hostname && (
                                <div className="text-xs font-mono text-slate-500 truncate">{srv.hostname}</div>
                            )}
                        </div>

                        <div className="mt-4 pt-3 border-t border-slate-900 flex items-center justify-between text-xs text-slate-500 font-mono">
                            <span className="flex items-center gap-1">
                                <Link className="w-3 h-3" />
                                :{srv.check_port}
                            </span>
                            {srv.response_ms != null ? (
                                <span className="flex items-center gap-1 text-emerald-400/70">
                                    <Zap className="w-3 h-3" />
                                    {srv.response_ms} ms
                                </span>
                            ) : srv.last_checked_at ? (
                                <span className="flex items-center gap-1">
                                    <Clock className="w-3 h-3" />
                                    checked
                                </span>
                            ) : (
                                <span className="text-slate-600">не проверялся</span>
                            )}
                        </div>
                    </div>
                ))}
            </div>
        </div>
    );
};
