import React from 'react';
import { Link, usePage } from '@inertiajs/react';
import {
    LayoutDashboard,
    Server,
    Globe,
    Cpu,
    Box,
    FileCode,
    Activity,
    Settings,
    X,
    Shield,
    PlusCircle
} from 'lucide-react';

interface SidebarProps {
    mobileOpen: boolean;
    setMobileOpen: (open: boolean) => void;
    onAddObjectClick: () => void;
}

export const Sidebar: React.FC<SidebarProps> = ({ mobileOpen, setMobileOpen, onAddObjectClick }) => {
    const { url } = usePage();
    const { vpsCount } = usePage().props as { vpsCount?: number };

    const navItems = [
        { name: 'Dashboard', href: '/', icon: LayoutDashboard, active: url === '/' || url === '/dashboard' },
        {
            name: 'VPS / Серверы',
            href: '/vps',
            icon: Server,
            count: vpsCount ?? 0,
            active: url.startsWith('/vps'),
        },
        { name: 'Сайты', href: '#websites', icon: Globe, count: 15 },
        { name: 'Proxmox', href: '#proxmox', icon: Cpu, count: 2 },
        { name: 'Контейнеры', href: '#containers', icon: Box, count: 2 },
        { name: 'WordPress', href: '#wordpress', icon: FileCode, count: 15 },
        { name: 'События', href: '#events', icon: Activity },
        { name: 'Настройки', href: '#settings', icon: Settings },
    ];

    const sidebarContent = (
        <div className="flex flex-col h-full bg-slate-900/90 backdrop-blur-md border-r border-slate-800 text-slate-300 w-64 p-4">
            {/* Brand Header */}
            <div className="flex items-center justify-between pb-6 border-b border-slate-800 px-2">
                <div className="flex items-center space-x-3">
                    <div className="w-9 h-9 rounded-lg bg-gradient-to-br from-cyan-500 to-blue-600 flex items-center justify-center text-white shadow-lg shadow-cyan-500/20 font-bold text-lg">
                        L
                    </div>
                    <div>
                        <h1 className="font-bold text-slate-100 text-lg leading-none tracking-tight">lavss monitor</h1>
                        <span className="text-xs text-slate-500 font-mono">v0.1.0-alpha</span>
                    </div>
                </div>
                <button
                    onClick={() => setMobileOpen(false)}
                    className="md:hidden text-slate-400 hover:text-white p-1"
                    aria-label="Close sidebar"
                >
                    <X className="w-5 h-5" />
                </button>
            </div>

            {/* Quick Action Button */}
            <div className="my-5">
                <button
                    onClick={onAddObjectClick}
                    className="w-full flex items-center justify-center space-x-2 bg-gradient-to-r from-blue-600 to-cyan-600 hover:from-blue-500 hover:to-cyan-500 text-white font-medium py-2.5 px-4 rounded-xl shadow-lg shadow-blue-500/20 transition-all duration-200 active:scale-[0.98]"
                >
                    <PlusCircle className="w-5 h-5" />
                    <span>Добавить объект</span>
                </button>
            </div>

            {/* Navigation Menu */}
            <nav className="flex-1 space-y-1.5 overflow-y-auto pr-1">
                {navItems.map((item) => {
                    const Icon = item.icon;
                    const isActive = item.active;
                    return (
                        <a
                            key={item.name}
                            href={item.href}
                            onClick={() => setMobileOpen(false)}
                            className={`flex items-center justify-between px-3 py-2.5 rounded-lg text-sm font-medium transition-colors ${
                                isActive
                                    ? 'bg-blue-600/20 text-cyan-400 border border-blue-500/30'
                                    : 'text-slate-400 hover:text-slate-100 hover:bg-slate-800/60'
                            }`}
                        >
                            <div className="flex items-center space-x-3">
                                <Icon className={`w-4 h-4 ${isActive ? 'text-cyan-400' : 'text-slate-400'}`} />
                                <span>{item.name}</span>
                            </div>
                            {item.count !== undefined && (
                                <span className={`text-xs px-2 py-0.5 rounded-full font-mono ${
                                    isActive ? 'bg-cyan-500/20 text-cyan-300' : 'bg-slate-800 text-slate-400'
                                }`}>
                                    {item.count}
                                </span>
                            )}
                        </a>
                    );
                })}
            </nav>

            {/* Footer Status */}
            <div className="pt-4 border-t border-slate-800 px-2 text-xs text-slate-500 flex items-center justify-between">
                <div className="flex items-center space-x-2">
                    <Shield className="w-4 h-4 text-emerald-400" />
                    <span>PVE Home Node</span>
                </div>
                <span className="font-mono text-emerald-400">WireGuard OK</span>
            </div>
        </div>
    );

    return (
        <>
            {/* Desktop Sidebar */}
            <aside className="hidden md:flex md:w-64 md:flex-col md:fixed md:inset-y-0 z-30">
                {sidebarContent}
            </aside>

            {/* Mobile Drawer Overlay */}
            {mobileOpen && (
                <div className="md:hidden fixed inset-0 z-50 flex">
                    <div
                        className="fixed inset-0 bg-slate-950/80 backdrop-blur-sm"
                        onClick={() => setMobileOpen(false)}
                    />
                    <div className="relative flex-1 flex flex-col max-w-xs w-full bg-slate-900">
                        {sidebarContent}
                    </div>
                </div>
            )}
        </>
    );
};
