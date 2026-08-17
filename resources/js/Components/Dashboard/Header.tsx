import React from 'react';
import { Menu, LogOut, ShieldCheck, User } from 'lucide-react';
import { router } from '@inertiajs/react';

interface HeaderProps {
    statusTitle: string;
    overallStatus: 'ok' | 'warning' | 'critical';
    userName: string;
    onMenuToggle: () => void;
}

export const Header: React.FC<HeaderProps> = ({
    statusTitle,
    overallStatus,
    userName,
    onMenuToggle,
}) => {
    const handleLogout = (e: React.FormEvent) => {
        e.preventDefault();
        router.post('/logout');
    };

    return (
        <header className="sticky top-0 z-20 bg-slate-950/80 backdrop-blur-md border-b border-slate-800 px-4 md:px-8 py-3.5 flex items-center justify-between">
            <div className="flex items-center space-x-4">
                <button
                    onClick={onMenuToggle}
                    className="md:hidden text-slate-400 hover:text-white p-1 rounded-lg hover:bg-slate-900"
                    aria-label="Toggle Navigation Menu"
                >
                    <Menu className="w-6 h-6" />
                </button>
                <div className="flex items-center space-x-3">
                    <span className={`inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold tracking-wide border ${
                        overallStatus === 'ok'
                            ? 'bg-emerald-500/10 text-emerald-400 border-emerald-500/30'
                            : overallStatus === 'warning'
                            ? 'bg-amber-500/10 text-amber-400 border-amber-500/30'
                            : 'bg-rose-500/10 text-rose-400 border-rose-500/30'
                    }`}>
                        <span className="w-2 h-2 rounded-full mr-2 animate-pulse bg-current" />
                        {statusTitle}
                    </span>
                    <span className="hidden lg:inline-block text-xs text-slate-400 border-l border-slate-800 pl-3">
                        monitor.lavss.ru
                    </span>
                </div>
            </div>

            <div className="flex items-center space-x-4">
                <div className="hidden sm:flex items-center space-x-2 text-xs text-slate-400 bg-slate-900 border border-slate-800 px-3 py-1.5 rounded-lg">
                    <ShieldCheck className="w-4 h-4 text-cyan-400" />
                    <span>Single Owner Session</span>
                </div>

                <div className="flex items-center space-x-3 border-l border-slate-800 pl-4">
                    <div className="flex items-center space-x-2">
                        <div className="w-8 h-8 rounded-full bg-slate-800 border border-slate-700 flex items-center justify-center text-slate-300">
                            <User className="w-4 h-4" />
                        </div>
                        <span className="hidden sm:inline-block text-sm font-medium text-slate-200">
                            {userName}
                        </span>
                    </div>

                    <form onSubmit={handleLogout}>
                        <button
                            type="submit"
                            title="Выйти из системы"
                            className="text-slate-400 hover:text-rose-400 hover:bg-rose-500/10 p-2 rounded-lg transition-colors"
                        >
                            <LogOut className="w-4 h-4" />
                        </button>
                    </form>
                </div>
            </div>
        </header>
    );
};
