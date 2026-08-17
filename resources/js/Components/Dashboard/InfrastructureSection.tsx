import React, { useState } from 'react';
import { Cpu, Box, Server, Layers, PlusCircle, X, CheckCircle2 } from 'lucide-react';
import { InfrastructureItem } from '@/types/dashboard';

interface InfrastructureSectionProps {
    items: InfrastructureItem[];
}

export const InfrastructureSection: React.FC<InfrastructureSectionProps> = ({ items }) => {
    return (
        <div id="proxmox" className="bg-slate-900/70 border border-slate-800 rounded-2xl p-5 mb-8 backdrop-blur-md">
            <div className="flex items-center justify-between mb-4 border-b border-slate-800 pb-3">
                <div className="flex items-center space-x-2 text-slate-200 font-semibold text-base">
                    <Cpu className="w-5 h-5 text-purple-400" />
                    <span>Инфраструктура Proxmox & LXC ({items.length})</span>
                </div>
                <span className="text-xs text-slate-400 font-mono">Proxmox API</span>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                {items.map((item) => (
                    <div
                        key={item.id}
                        className="bg-slate-950/80 border border-slate-800 hover:border-slate-700 rounded-xl p-4 flex items-start space-x-3.5 transition-colors"
                    >
                        <div className="p-2.5 rounded-lg bg-purple-500/10 text-purple-400 shrink-0 mt-0.5">
                            {item.type.includes('LXC') ? <Box className="w-5 h-5" /> : <Layers className="w-5 h-5" />}
                        </div>
                        <div className="flex-1 min-w-0">
                            <div className="flex items-center justify-between">
                                <h4 className="text-sm font-bold text-slate-100 truncate">{item.name}</h4>
                                <span className="w-2 h-2 rounded-full bg-emerald-400" />
                            </div>
                            <span className="text-xs text-purple-300/80 font-mono block mt-0.5">{item.type}</span>
                            <p className="text-xs text-slate-400 mt-2 font-mono bg-slate-900 px-2 py-1 rounded border border-slate-800/80">
                                {item.details}
                            </p>
                        </div>
                    </div>
                ))}
            </div>
        </div>
    );
};

interface QuickActionsModalProps {
    isOpen: boolean;
    onClose: () => void;
}

export const QuickActionsModal: React.FC<QuickActionsModalProps> = ({ isOpen, onClose }) => {
    const [selectedType, setSelectedType] = useState('vps');
    const [objectName, setObjectName] = useState('');
    const [submitted, setSubmitted] = useState(false);

    if (!isOpen) return null;

    const objectTypes = [
        { id: 'vps', name: 'VPS (Linux)' },
        { id: 'server', name: 'Dedicated Server' },
        { id: 'proxmox', name: 'Proxmox VE Node' },
        { id: 'container', name: 'LXC Container' },
        { id: 'website', name: 'Website (HTTP)' },
        { id: 'wordpress', name: 'WordPress Site' },
    ];

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        setSubmitted(true);
        setTimeout(() => {
            setSubmitted(false);
            setObjectName('');
            onClose();
        }, 1200);
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div className="fixed inset-0 bg-slate-950/80 backdrop-blur-sm" onClick={onClose} />
            <div className="relative w-full max-w-md bg-slate-900 border border-slate-800 rounded-2xl p-6 shadow-2xl z-10">
                <div className="flex items-center justify-between pb-4 border-b border-slate-800 mb-5">
                    <div className="flex items-center space-x-2 text-slate-100 font-bold text-lg">
                        <PlusCircle className="w-5 h-5 text-cyan-400" />
                        <span>Добавить объект</span>
                    </div>
                    <button onClick={onClose} className="text-slate-400 hover:text-white p-1">
                        <X className="w-5 h-5" />
                    </button>
                </div>

                {submitted ? (
                    <div className="py-8 text-center space-y-3">
                        <CheckCircle2 className="w-12 h-12 text-emerald-400 mx-auto animate-bounce" />
                        <h4 className="text-lg font-bold text-slate-100">Объект успешно добавлен</h4>
                        <p className="text-xs text-slate-400 font-mono">MonitoredObject создан в демо-режиме.</p>
                    </div>
                ) : (
                    <form onSubmit={handleSubmit} className="space-y-4">
                        <div>
                            <label className="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2">
                                Тип объекта
                            </label>
                            <select
                                value={selectedType}
                                onChange={(e) => setSelectedType(e.target.value)}
                                className="w-full bg-slate-950 border border-slate-800 text-slate-200 text-sm rounded-xl p-3 focus:border-cyan-500 focus:ring-1 focus:ring-cyan-500 outline-none"
                            >
                                {objectTypes.map((t) => (
                                    <option key={t.id} value={t.id}>
                                        {t.name}
                                    </option>
                                ))}
                            </select>
                        </div>

                        <div>
                            <label className="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2">
                                Название / URL / IP
                            </label>
                            <input
                                type="text"
                                required
                                value={objectName}
                                onChange={(e) => setObjectName(e.target.value)}
                                placeholder="например: VPS-04 или site.ru"
                                className="w-full bg-slate-950 border border-slate-800 text-slate-200 text-sm rounded-xl p-3 focus:border-cyan-500 focus:ring-1 focus:ring-cyan-500 outline-none placeholder:text-slate-600 font-mono"
                            />
                        </div>

                        <div className="pt-4 border-t border-slate-800 flex justify-end space-x-3">
                            <button
                                type="button"
                                onClick={onClose}
                                className="px-4 py-2.5 rounded-xl border border-slate-800 text-slate-400 hover:text-slate-200 text-sm font-medium transition-colors"
                            >
                                Отмена
                            </button>
                            <button
                                type="submit"
                                className="px-5 py-2.5 rounded-xl bg-gradient-to-r from-blue-600 to-cyan-600 hover:from-blue-500 hover:to-cyan-500 text-white text-sm font-medium shadow-lg shadow-cyan-500/20 transition-all"
                            >
                                Сохранить
                            </button>
                        </div>
                    </form>
                )}
            </div>
        </div>
    );
};
