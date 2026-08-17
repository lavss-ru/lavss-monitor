import React, { FormEventHandler } from 'react';
import { Head, useForm } from '@inertiajs/react';
import { Shield, Lock, Mail, Activity } from 'lucide-react';

export default function Login({ status }: { status?: string }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        email: 'lavss@lavss.ru',
        password: 'password',
        remember: true,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post('/login', {
            onFinish: () => reset('password'),
        });
    };

    return (
        <div className="min-h-screen bg-slate-950 text-slate-100 flex flex-col justify-center items-center p-4 relative overflow-hidden">
            <Head title="Вход — lavss monitor" />

            {/* Subtle background glow effect */}
            <div className="absolute -top-40 -left-40 w-96 h-96 bg-cyan-500/10 rounded-full blur-3xl pointer-events-none" />
            <div className="absolute -bottom-40 -right-40 w-96 h-96 bg-blue-600/10 rounded-full blur-3xl pointer-events-none" />

            <div className="w-full max-w-md bg-slate-900/80 backdrop-blur-xl border border-slate-800 rounded-2xl p-8 shadow-2xl z-10 relative">
                {/* Brand Header */}
                <div className="text-center mb-8">
                    <div className="w-14 h-14 rounded-2xl bg-gradient-to-br from-cyan-500 to-blue-600 flex items-center justify-center text-white shadow-xl shadow-cyan-500/20 font-extrabold text-2xl mx-auto mb-4">
                        L
                    </div>
                    <h1 className="text-2xl font-bold text-slate-100 tracking-tight">lavss monitor</h1>
                    <p className="text-xs text-slate-400 mt-1 font-mono">Пульт управления IT-инфраструктурой</p>
                </div>

                {status && (
                    <div className="mb-6 p-3 rounded-xl bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-xs font-mono text-center">
                        {status}
                    </div>
                )}

                <form onSubmit={submit} className="space-y-5">
                    <div>
                        <label className="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2">
                            Электронная почта
                        </label>
                        <div className="relative">
                            <div className="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-500">
                                <Mail className="w-4 h-4" />
                            </div>
                            <input
                                id="email"
                                type="email"
                                name="email"
                                value={data.email}
                                onChange={(e) => setData('email', e.target.value)}
                                className="w-full bg-slate-950 border border-slate-800 text-slate-200 text-sm rounded-xl pl-10 pr-4 py-3 focus:border-cyan-500 focus:ring-1 focus:ring-cyan-500 outline-none font-mono transition-colors"
                                required
                                autoFocus
                            />
                        </div>
                        {errors.email && (
                            <p className="mt-1 text-xs text-rose-400 font-mono">{errors.email}</p>
                        )}
                    </div>

                    <div>
                        <label className="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2">
                            Пароль
                        </label>
                        <div className="relative">
                            <div className="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-500">
                                <Lock className="w-4 h-4" />
                            </div>
                            <input
                                id="password"
                                type="password"
                                name="password"
                                value={data.password}
                                onChange={(e) => setData('password', e.target.value)}
                                className="w-full bg-slate-950 border border-slate-800 text-slate-200 text-sm rounded-xl pl-10 pr-4 py-3 focus:border-cyan-500 focus:ring-1 focus:ring-cyan-500 outline-none font-mono transition-colors"
                                required
                            />
                        </div>
                        {errors.password && (
                            <p className="mt-1 text-xs text-rose-400 font-mono">{errors.password}</p>
                        )}
                    </div>

                    <div className="flex items-center justify-between text-xs text-slate-400 pt-1">
                        <label className="flex items-center space-x-2 cursor-pointer">
                            <input
                                type="checkbox"
                                checked={data.remember}
                                onChange={(e) => setData('remember', e.target.checked)}
                                className="rounded border-slate-800 bg-slate-950 text-cyan-500 focus:ring-cyan-500 focus:ring-offset-slate-900"
                            />
                            <span>Запомнить сессию</span>
                        </label>
                        <span className="text-slate-500 font-mono">Single Owner Auth</span>
                    </div>

                    <button
                        type="submit"
                        disabled={processing}
                        className="w-full mt-4 bg-gradient-to-r from-blue-600 to-cyan-600 hover:from-blue-500 hover:to-cyan-500 text-white font-semibold py-3 px-4 rounded-xl shadow-lg shadow-cyan-500/20 transition-all duration-200 active:scale-[0.98] disabled:opacity-50"
                    >
                        {processing ? 'Авторизация...' : 'Войти в панель'}
                    </button>
                </form>

                <div className="mt-8 pt-4 border-t border-slate-800/80 text-center text-xs text-slate-500 font-mono flex items-center justify-center space-x-2">
                    <Shield className="w-3.5 h-3.5 text-cyan-400" />
                    <span>Protected Session &bull; monitor.lavss.ru</span>
                </div>
            </div>
        </div>
    );
}
