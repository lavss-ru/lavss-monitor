import React from 'react';
import { Server, HardDrive, Cpu, Activity, Clock } from 'lucide-react';
import { VPSItem } from '@/types/dashboard';

interface VpsSectionProps {
    servers: VPSItem[];
}

export const VpsSection: React.FC<VpsSectionProps> = ({ servers }) => {
    return (
        <div id="vps" className="bg-slate-900/70 border border-slate-800 rounded-2xl p-5 mb-8 backdrop-blur-md">
            <div className="flex items-center justify-between mb-4 border-b border-slate-800 pb-3">
                <div className="flex items-center space-x-2 text-slate-200 font-semibold text-base">
                    <Server className="w-5 h-5 text-blue-400" />
                    <span>VPS / Серверы ({servers.length})</span>
                </div>
                <span className="text-xs text-slate-400 font-mono">Node Exporter + Prometheus</span>
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
                {servers.map((srv) => (
                    <div
                        key={srv.id}
                        className={`bg-slate-950/80 border rounded-xl p-4 flex flex-col justify-between transition-all ${
                            srv.status === 'warning'
                                ? 'border-amber-500/40 shadow-lg shadow-amber-500/5'
                                : 'border-slate-800 hover:border-slate-700'
                        }`}
                    >
                        <div>
                            <div className="flex items-center justify-between mb-2">
                                <h3 className="font-bold text-slate-100 text-base">{srv.name}</h3>
                                <span className={`px-2 py-0.5 rounded text-xs font-mono font-medium ${
                                    srv.status === 'ok'
                                        ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20'
                                        : 'bg-amber-500/10 text-amber-400 border border-amber-500/20'
                                }`}>
                                    {srv.status === 'ok' ? '🟢 ONLINE' : '🟡 ATTENTION'}
                                </span>
                            </div>
                            <div className="text-xs font-mono text-slate-400 mb-4">{srv.ip}</div>

                            {/* Resource Bars */}
                            <div className="space-y-3">
                                <div>
                                    <div className="flex justify-between text-xs mb-1">
                                        <span className="text-slate-400 flex items-center gap-1"><Cpu className="w-3 h-3 text-cyan-400"/> CPU</span>
                                        <span className="font-mono text-slate-200">{srv.cpu}%</span>
                                    </div>
                                    <div className="w-full bg-slate-900 rounded-full h-1.5 overflow-hidden">
                                        <div className="bg-cyan-500 h-full rounded-full transition-all" style={{ width: `${srv.cpu}%` }} />
                                    </div>
                                </div>

                                <div>
                                    <div className="flex justify-between text-xs mb-1">
                                        <span className="text-slate-400 flex items-center gap-1"><Activity className="w-3 h-3 text-blue-400"/> RAM</span>
                                        <span className="font-mono text-slate-200">{srv.ram}%</span>
                                    </div>
                                    <div className="w-full bg-slate-900 rounded-full h-1.5 overflow-hidden">
                                        <div className="bg-blue-500 h-full rounded-full transition-all" style={{ width: `${srv.ram}%` }} />
                                    </div>
                                </div>

                                <div>
                                    <div className="flex justify-between text-xs mb-1">
                                        <span className="text-slate-400 flex items-center gap-1"><HardDrive className="w-3 h-3 text-amber-400"/> Disk</span>
                                        <span className={`font-mono ${srv.disk > 80 ? 'text-amber-400 font-bold' : 'text-slate-200'}`}>{srv.disk}%</span>
                                    </div>
                                    <div className="w-full bg-slate-900 rounded-full h-1.5 overflow-hidden">
                                        <div className={`h-full rounded-full transition-all ${srv.disk > 80 ? 'bg-amber-500' : 'bg-emerald-500'}`} style={{ width: `${srv.disk}%` }} />
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div className="mt-4 pt-3 border-t border-slate-900 flex items-center justify-between text-xs text-slate-500 font-mono">
                            <span>Load: {srv.load}</span>
                            <span className="flex items-center gap-1"><Clock className="w-3 h-3"/> {srv.uptime}</span>
                        </div>
                    </div>
                ))}
            </div>
        </div>
    );
};
