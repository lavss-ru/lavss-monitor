import React, { useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import { Sidebar } from '@/Components/Dashboard/Sidebar';
import { Header } from '@/Components/Dashboard/Header';
import {
    Server,
    PlusCircle,
    RefreshCw,
    Wifi,
    WifiOff,
    HelpCircle,
    Clock,
    Zap,
    Globe,
    Shield,
    X,
    CheckCircle,
    Pencil,
    Trash2,
} from 'lucide-react';
import { VpsRecord } from '@/types/vps';

interface VpsIndexProps {
    vpsList: VpsRecord[];
}

/* ─── Status badge ─── */
const StatusBadge: React.FC<{ status: VpsRecord['status'] }> = ({ status }) => {
    if (status === 'online') {
        return (
            <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs font-mono font-semibold bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                <Wifi className="w-3 h-3" /> ONLINE
            </span>
        );
    }
    if (status === 'offline') {
        return (
            <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs font-mono font-semibold bg-rose-500/10 text-rose-400 border border-rose-500/20">
                <WifiOff className="w-3 h-3" /> OFFLINE
            </span>
        );
    }
    return (
        <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs font-mono font-semibold bg-slate-700/50 text-slate-400 border border-slate-700">
            <HelpCircle className="w-3 h-3" /> UNKNOWN
        </span>
    );
};

/* ─── Add VPS Modal ─── */
interface AddVpsModalProps {
    isOpen: boolean;
    onClose: () => void;
}

const AddVpsModal: React.FC<AddVpsModalProps> = ({ isOpen, onClose }) => {
    const [form, setForm] = useState({
        name: '',
        hostname: '',
        ip_address: '',
        description: '',
        check_port: 22,
        enabled: true,
    });
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [submitting, setSubmitting] = useState(false);

    if (!isOpen) return null;

    const handleChange = (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) => {
        const { name, value, type } = e.target;
        const checked = (e.target as HTMLInputElement).checked;
        setForm((prev) => ({
            ...prev,
            [name]: type === 'checkbox' ? checked : name === 'check_port' ? parseInt(value, 10) || 22 : value,
        }));
        setErrors((prev) => ({ ...prev, [name]: '' }));
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        setSubmitting(true);
        router.post('/vps', form, {
            onError: (err) => {
                setErrors(err as Record<string, string>);
                setSubmitting(false);
            },
            onSuccess: () => {
                setForm({ name: '', hostname: '', ip_address: '', description: '', check_port: 22, enabled: true });
                setErrors({});
                setSubmitting(false);
                onClose();
            },
        });
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div className="absolute inset-0 bg-slate-950/80 backdrop-blur-sm" onClick={onClose} />
            <div className="relative w-full max-w-lg bg-slate-900 border border-slate-700 rounded-2xl shadow-2xl shadow-black/50 p-6">
                {/* Header */}
                <div className="flex items-center justify-between mb-6">
                    <div className="flex items-center gap-3">
                        <div className="p-2 rounded-xl bg-blue-500/10 border border-blue-500/20">
                            <Server className="w-5 h-5 text-blue-400" />
                        </div>
                        <div>
                            <h2 className="text-lg font-bold text-slate-100">Добавить VPS</h2>
                            <p className="text-xs text-slate-500">TCP connect мониторинг</p>
                        </div>
                    </div>
                    <button
                        onClick={onClose}
                        className="p-1.5 rounded-lg text-slate-400 hover:text-white hover:bg-slate-800 transition-colors"
                        aria-label="Закрыть"
                    >
                        <X className="w-5 h-5" />
                    </button>
                </div>

                <form onSubmit={handleSubmit} className="space-y-4">
                    {/* Name */}
                    <div>
                        <label className="block text-xs font-semibold text-slate-400 mb-1.5 uppercase tracking-wider">
                            Название <span className="text-rose-400">*</span>
                        </label>
                        <input
                            type="text"
                            name="name"
                            id="vps-name"
                            value={form.name}
                            onChange={handleChange}
                            placeholder="VPS-01, Home Server..."
                            className={`w-full bg-slate-950 border rounded-xl px-4 py-2.5 text-slate-100 text-sm placeholder-slate-600 focus:outline-none focus:ring-2 focus:ring-blue-500/50 transition-all ${
                                errors.name ? 'border-rose-500/60' : 'border-slate-700 focus:border-blue-500/60'
                            }`}
                        />
                        {errors.name && <p className="mt-1 text-xs text-rose-400">{errors.name}</p>}
                    </div>

                    {/* IP Address */}
                    <div>
                        <label className="block text-xs font-semibold text-slate-400 mb-1.5 uppercase tracking-wider">
                            IP-адрес <span className="text-rose-400">*</span>
                        </label>
                        <input
                            type="text"
                            name="ip_address"
                            id="vps-ip-address"
                            value={form.ip_address}
                            onChange={handleChange}
                            placeholder="192.168.1.1"
                            className={`w-full bg-slate-950 border rounded-xl px-4 py-2.5 text-slate-100 text-sm font-mono placeholder-slate-600 focus:outline-none focus:ring-2 focus:ring-blue-500/50 transition-all ${
                                errors.ip_address ? 'border-rose-500/60' : 'border-slate-700 focus:border-blue-500/60'
                            }`}
                        />
                        {errors.ip_address && <p className="mt-1 text-xs text-rose-400">{errors.ip_address}</p>}
                    </div>

                    {/* Hostname & Port (side by side) */}
                    <div className="grid grid-cols-2 gap-3">
                        <div>
                            <label className="block text-xs font-semibold text-slate-400 mb-1.5 uppercase tracking-wider">
                                Hostname
                            </label>
                            <input
                                type="text"
                                name="hostname"
                                id="vps-hostname"
                                value={form.hostname}
                                onChange={handleChange}
                                placeholder="server.example.com"
                                className="w-full bg-slate-950 border border-slate-700 focus:border-blue-500/60 rounded-xl px-4 py-2.5 text-slate-100 text-sm placeholder-slate-600 focus:outline-none focus:ring-2 focus:ring-blue-500/50 transition-all"
                            />
                        </div>
                        <div>
                            <label className="block text-xs font-semibold text-slate-400 mb-1.5 uppercase tracking-wider">
                                Порт проверки <span className="text-rose-400">*</span>
                            </label>
                            <input
                                type="number"
                                name="check_port"
                                id="vps-check-port"
                                value={form.check_port}
                                onChange={handleChange}
                                min={1}
                                max={65535}
                                className={`w-full bg-slate-950 border rounded-xl px-4 py-2.5 text-slate-100 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-blue-500/50 transition-all ${
                                    errors.check_port ? 'border-rose-500/60' : 'border-slate-700 focus:border-blue-500/60'
                                }`}
                            />
                            {errors.check_port && <p className="mt-1 text-xs text-rose-400">{errors.check_port}</p>}
                        </div>
                    </div>

                    {/* Description */}
                    <div>
                        <label className="block text-xs font-semibold text-slate-400 mb-1.5 uppercase tracking-wider">
                            Описание
                        </label>
                        <textarea
                            name="description"
                            id="vps-description"
                            value={form.description}
                            onChange={handleChange}
                            rows={2}
                            placeholder="Необязательное описание сервера..."
                            className="w-full bg-slate-950 border border-slate-700 focus:border-blue-500/60 rounded-xl px-4 py-2.5 text-slate-100 text-sm placeholder-slate-600 focus:outline-none focus:ring-2 focus:ring-blue-500/50 transition-all resize-none"
                        />
                    </div>

                    {/* Enabled toggle */}
                    <div className="flex items-center gap-3 py-1">
                        <input
                            type="checkbox"
                            name="enabled"
                            id="vps-enabled"
                            checked={form.enabled}
                            onChange={handleChange}
                            className="w-4 h-4 accent-blue-500 cursor-pointer"
                        />
                        <label htmlFor="vps-enabled" className="text-sm text-slate-300 cursor-pointer select-none">
                            Включить мониторинг
                        </label>
                    </div>

                    {/* Actions */}
                    <div className="flex items-center gap-3 pt-2">
                        <button
                            type="submit"
                            id="vps-submit-btn"
                            disabled={submitting}
                            className="flex-1 flex items-center justify-center gap-2 bg-gradient-to-r from-blue-600 to-cyan-600 hover:from-blue-500 hover:to-cyan-500 disabled:opacity-50 disabled:cursor-not-allowed text-white font-semibold py-2.5 rounded-xl transition-all duration-200 active:scale-[0.98]"
                        >
                            {submitting ? (
                                <RefreshCw className="w-4 h-4 animate-spin" />
                            ) : (
                                <CheckCircle className="w-4 h-4" />
                            )}
                            {submitting ? 'Сохранение...' : 'Добавить VPS'}
                        </button>
                        <button
                            type="button"
                            onClick={onClose}
                            className="px-4 py-2.5 rounded-xl text-slate-400 hover:text-white hover:bg-slate-800 transition-colors text-sm font-medium"
                        >
                            Отмена
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
};

/* ─── Edit VPS Modal ─── */
interface EditVpsModalProps {
    vps: VpsRecord | null;
    onClose: () => void;
}

const EditVpsModal: React.FC<EditVpsModalProps> = ({ vps, onClose }) => {
    const [form, setForm] = React.useState({
        name: '',
        hostname: '',
        ip_address: '',
        description: '',
        check_port: 22,
        enabled: true,
    });
    const [errors, setErrors] = React.useState<Record<string, string>>({});
    const [submitting, setSubmitting] = React.useState(false);

    // Populate form when vps changes
    React.useEffect(() => {
        if (vps) {
            setForm({
                name: vps.name,
                hostname: vps.hostname ?? '',
                ip_address: vps.ip_address,
                description: vps.description ?? '',
                check_port: vps.check_port,
                enabled: vps.enabled,
            });
            setErrors({});
        }
    }, [vps]);

    if (!vps) return null;

    const handleChange = (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) => {
        const { name, value, type } = e.target;
        const checked = (e.target as HTMLInputElement).checked;
        setForm((prev) => ({
            ...prev,
            [name]: type === 'checkbox' ? checked : name === 'check_port' ? parseInt(value, 10) || 22 : value,
        }));
        setErrors((prev) => ({ ...prev, [name]: '' }));
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        setSubmitting(true);
        router.put(`/vps/${vps.id}`, form, {
            onError: (err) => {
                setErrors(err as Record<string, string>);
                setSubmitting(false);
            },
            onSuccess: () => {
                setSubmitting(false);
                onClose();
            },
        });
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div className="absolute inset-0 bg-slate-950/80 backdrop-blur-sm" onClick={onClose} />
            <div className="relative w-full max-w-lg bg-slate-900 border border-slate-700 rounded-2xl shadow-2xl shadow-black/50 p-6">
                <div className="flex items-center justify-between mb-6">
                    <div className="flex items-center gap-3">
                        <div className="p-2 rounded-xl bg-cyan-500/10 border border-cyan-500/20">
                            <Pencil className="w-5 h-5 text-cyan-400" />
                        </div>
                        <div>
                            <h2 className="text-lg font-bold text-slate-100">Редактировать VPS</h2>
                            <p className="text-xs text-slate-500 font-mono truncate max-w-xs">{vps.name}</p>
                        </div>
                    </div>
                    <button onClick={onClose} className="p-1.5 rounded-lg text-slate-400 hover:text-white hover:bg-slate-800 transition-colors" aria-label="Закрыть">
                        <X className="w-5 h-5" />
                    </button>
                </div>

                <form onSubmit={handleSubmit} className="space-y-4">
                    <div>
                        <label className="block text-xs font-semibold text-slate-400 mb-1.5 uppercase tracking-wider">
                            Название <span className="text-rose-400">*</span>
                        </label>
                        <input type="text" name="name" id="edit-vps-name" value={form.name} onChange={handleChange}
                            className={`w-full bg-slate-950 border rounded-xl px-4 py-2.5 text-slate-100 text-sm placeholder-slate-600 focus:outline-none focus:ring-2 focus:ring-cyan-500/50 transition-all ${
                                errors.name ? 'border-rose-500/60' : 'border-slate-700 focus:border-cyan-500/60'
                            }`} />
                        {errors.name && <p className="mt-1 text-xs text-rose-400">{errors.name}</p>}
                    </div>

                    <div>
                        <label className="block text-xs font-semibold text-slate-400 mb-1.5 uppercase tracking-wider">
                            IP-адрес <span className="text-rose-400">*</span>
                        </label>
                        <input type="text" name="ip_address" id="edit-vps-ip" value={form.ip_address} onChange={handleChange}
                            className={`w-full bg-slate-950 border rounded-xl px-4 py-2.5 text-slate-100 text-sm font-mono placeholder-slate-600 focus:outline-none focus:ring-2 focus:ring-cyan-500/50 transition-all ${
                                errors.ip_address ? 'border-rose-500/60' : 'border-slate-700 focus:border-cyan-500/60'
                            }`} />
                        {errors.ip_address && <p className="mt-1 text-xs text-rose-400">{errors.ip_address}</p>}
                    </div>

                    <div className="grid grid-cols-2 gap-3">
                        <div>
                            <label className="block text-xs font-semibold text-slate-400 mb-1.5 uppercase tracking-wider">Hostname</label>
                            <input type="text" name="hostname" id="edit-vps-hostname" value={form.hostname} onChange={handleChange}
                                className="w-full bg-slate-950 border border-slate-700 focus:border-cyan-500/60 rounded-xl px-4 py-2.5 text-slate-100 text-sm placeholder-slate-600 focus:outline-none focus:ring-2 focus:ring-cyan-500/50 transition-all" />
                        </div>
                        <div>
                            <label className="block text-xs font-semibold text-slate-400 mb-1.5 uppercase tracking-wider">
                                Порт <span className="text-rose-400">*</span>
                            </label>
                            <input type="number" name="check_port" id="edit-vps-port" value={form.check_port} onChange={handleChange} min={1} max={65535}
                                className={`w-full bg-slate-950 border rounded-xl px-4 py-2.5 text-slate-100 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-cyan-500/50 transition-all ${
                                    errors.check_port ? 'border-rose-500/60' : 'border-slate-700 focus:border-cyan-500/60'
                                }`} />
                            {errors.check_port && <p className="mt-1 text-xs text-rose-400">{errors.check_port}</p>}
                        </div>
                    </div>

                    <div>
                        <label className="block text-xs font-semibold text-slate-400 mb-1.5 uppercase tracking-wider">Описание</label>
                        <textarea name="description" id="edit-vps-description" value={form.description} onChange={handleChange} rows={2}
                            className="w-full bg-slate-950 border border-slate-700 focus:border-cyan-500/60 rounded-xl px-4 py-2.5 text-slate-100 text-sm placeholder-slate-600 focus:outline-none focus:ring-2 focus:ring-cyan-500/50 transition-all resize-none" />
                    </div>

                    <div className="flex items-center gap-3 py-1">
                        <input type="checkbox" name="enabled" id="edit-vps-enabled" checked={form.enabled} onChange={handleChange}
                            className="w-4 h-4 accent-cyan-500 cursor-pointer" />
                        <label htmlFor="edit-vps-enabled" className="text-sm text-slate-300 cursor-pointer select-none">Включить мониторинг</label>
                    </div>

                    <div className="flex items-center gap-3 pt-2">
                        <button type="submit" id="edit-vps-submit-btn" disabled={submitting}
                            className="flex-1 flex items-center justify-center gap-2 bg-gradient-to-r from-cyan-600 to-blue-600 hover:from-cyan-500 hover:to-blue-500 disabled:opacity-50 disabled:cursor-not-allowed text-white font-semibold py-2.5 rounded-xl transition-all duration-200 active:scale-[0.98]">
                            {submitting ? <RefreshCw className="w-4 h-4 animate-spin" /> : <CheckCircle className="w-4 h-4" />}
                            {submitting ? 'Сохранение...' : 'Сохранить'}
                        </button>
                        <button type="button" onClick={onClose} className="px-4 py-2.5 rounded-xl text-slate-400 hover:text-white hover:bg-slate-800 transition-colors text-sm font-medium">
                            Отмена
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
};

/* ─── Delete Confirm Modal ─── */
interface DeleteConfirmModalProps {
    vps: VpsRecord | null;
    onClose: () => void;
}

const DeleteConfirmModal: React.FC<DeleteConfirmModalProps> = ({ vps, onClose }) => {
    const [deleting, setDeleting] = React.useState(false);

    if (!vps) return null;

    const handleDelete = () => {
        setDeleting(true);
        router.delete(`/vps/${vps.id}`, {
            onFinish: () => {
                setDeleting(false);
                onClose();
            },
        });
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div className="absolute inset-0 bg-slate-950/80 backdrop-blur-sm" onClick={onClose} />
            <div className="relative w-full max-w-md bg-slate-900 border border-rose-500/30 rounded-2xl shadow-2xl shadow-black/50 p-6">
                <div className="flex items-center gap-3 mb-4">
                    <div className="p-2 rounded-xl bg-rose-500/10 border border-rose-500/20">
                        <Trash2 className="w-5 h-5 text-rose-400" />
                    </div>
                    <h2 className="text-lg font-bold text-slate-100">Удалить VPS</h2>
                </div>
                <p className="text-sm text-slate-300 mb-2">
                    Вы уверены, что хотите удалить:
                </p>
                <p className="text-base font-bold text-rose-300 font-mono mb-4 px-3 py-2 bg-rose-500/5 border border-rose-500/20 rounded-xl">
                    {vps.name}
                </p>
                <p className="text-xs text-slate-500 mb-6">Это действие необратимо. Все данные о сервере будут удалены.</p>
                <div className="flex items-center gap-3">
                    <button onClick={handleDelete} disabled={deleting} id="delete-vps-confirm-btn"
                        className="flex-1 flex items-center justify-center gap-2 bg-rose-600 hover:bg-rose-500 disabled:opacity-50 disabled:cursor-not-allowed text-white font-semibold py-2.5 rounded-xl transition-all active:scale-[0.98]">
                        {deleting ? <RefreshCw className="w-4 h-4 animate-spin" /> : <Trash2 className="w-4 h-4" />}
                        {deleting ? 'Удаление...' : 'Удалить'}
                    </button>
                    <button onClick={onClose} className="px-4 py-2.5 rounded-xl text-slate-400 hover:text-white hover:bg-slate-800 transition-colors text-sm font-medium">
                        Отмена
                    </button>
                </div>
            </div>
        </div>
    );
};

/* ─── Main Page ─── */
export default function VpsIndex({ vpsList }: VpsIndexProps) {
    const user = usePage().props.auth.user;
    const [mobileOpen, setMobileOpen] = useState(false);
    const [addModalOpen, setAddModalOpen] = useState(false);
    const [editVps, setEditVps] = useState<VpsRecord | null>(null);
    const [deleteVps, setDeleteVps] = useState<VpsRecord | null>(null);
    const [checkingAll, setCheckingAll] = useState(false);
    const [checkingId, setCheckingId] = useState<number | null>(null);

    // Summary stats
    const total   = vpsList.length;
    const online  = vpsList.filter((v) => v.status === 'online').length;
    const offline = vpsList.filter((v) => v.status === 'offline').length;
    const unknown = vpsList.filter((v) => v.status === 'unknown').length;

    const overallStatus: 'ok' | 'warning' | 'critical' = offline > 0 ? 'warning' : 'ok';
    const statusTitle = offline > 0 ? `🟡 Требуют внимания — ${offline}` : '🟢 VPS в норме';

    const handleCheck = (vps: VpsRecord) => {
        setCheckingId(vps.id);
        router.post(`/vps/${vps.id}/check`, {}, {
            onFinish: () => setCheckingId(null),
        });
    };

    const handleCheckAll = () => {
        setCheckingAll(true);
        router.post('/vps/check-all', {}, {
            onFinish: () => setCheckingAll(false),
        });
    };

    const formatCheckedAt = (iso: string | null): string => {
        if (!iso) return 'никогда';
        const date = new Date(iso);
        return date.toLocaleString('ru-RU', {
            day: '2-digit', month: '2-digit', year: '2-digit',
            hour: '2-digit', minute: '2-digit',
        });
    };

    return (
        <div className="min-h-screen bg-slate-950 text-slate-100 flex flex-col font-sans antialiased">
            <Head title="VPS / Серверы — lavss monitor" />

            <Sidebar
                mobileOpen={mobileOpen}
                setMobileOpen={setMobileOpen}
                onAddObjectClick={() => setAddModalOpen(true)}
            />

            <div className="md:pl-64 flex flex-col flex-1 min-w-0">
                <Header
                    statusTitle={statusTitle}
                    overallStatus={overallStatus}
                    userName={user.name}
                    onMenuToggle={() => setMobileOpen(true)}
                />

                <main className="flex-1 p-4 sm:p-6 md:p-8 max-w-7xl w-full mx-auto">
                    {/* Page heading */}
                    <div className="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                        <div>
                            <h2 className="text-xl font-bold text-slate-100 tracking-tight flex items-center gap-2">
                                <Server className="w-5 h-5 text-blue-400" />
                                VPS / Серверы
                            </h2>
                            <p className="text-xs text-slate-400 font-mono mt-1">
                                TCP connect мониторинг доступности
                            </p>
                        </div>
                        <div className="flex items-center gap-3">
                            <button
                                id="check-all-btn"
                                onClick={handleCheckAll}
                                disabled={checkingAll || total === 0}
                                className="flex items-center gap-2 px-4 py-2 rounded-xl border border-slate-700 text-slate-300 hover:text-white hover:border-slate-600 hover:bg-slate-800/60 disabled:opacity-40 disabled:cursor-not-allowed transition-all text-sm font-medium"
                            >
                                <RefreshCw className={`w-4 h-4 ${checkingAll ? 'animate-spin text-cyan-400' : ''}`} />
                                Проверить все
                            </button>
                            <button
                                id="add-vps-btn"
                                onClick={() => setAddModalOpen(true)}
                                className="flex items-center gap-2 px-4 py-2 rounded-xl bg-gradient-to-r from-blue-600 to-cyan-600 hover:from-blue-500 hover:to-cyan-500 text-white font-semibold transition-all text-sm shadow-lg shadow-blue-500/20 active:scale-[0.98]"
                            >
                                <PlusCircle className="w-4 h-4" />
                                Добавить VPS
                            </button>
                        </div>
                    </div>

                    {/* Summary strip */}
                    {total > 0 && (
                        <div className="flex flex-wrap gap-3 mb-6">
                            <div className="flex items-center gap-2 bg-slate-900/70 border border-slate-800 rounded-xl px-4 py-2 text-sm">
                                <Server className="w-4 h-4 text-slate-400" />
                                <span className="text-slate-400">Всего:</span>
                                <span className="font-bold text-slate-100 font-mono">{total}</span>
                            </div>
                            <div className="flex items-center gap-2 bg-slate-900/70 border border-emerald-500/20 rounded-xl px-4 py-2 text-sm">
                                <Wifi className="w-4 h-4 text-emerald-400" />
                                <span className="text-slate-400">Online:</span>
                                <span className="font-bold text-emerald-400 font-mono">{online}</span>
                            </div>
                            {offline > 0 && (
                                <div className="flex items-center gap-2 bg-slate-900/70 border border-rose-500/20 rounded-xl px-4 py-2 text-sm">
                                    <WifiOff className="w-4 h-4 text-rose-400" />
                                    <span className="text-slate-400">Offline:</span>
                                    <span className="font-bold text-rose-400 font-mono">{offline}</span>
                                </div>
                            )}
                            {unknown > 0 && (
                                <div className="flex items-center gap-2 bg-slate-900/70 border border-slate-700 rounded-xl px-4 py-2 text-sm">
                                    <HelpCircle className="w-4 h-4 text-slate-400" />
                                    <span className="text-slate-400">Не проверено:</span>
                                    <span className="font-bold text-slate-300 font-mono">{unknown}</span>
                                </div>
                            )}
                        </div>
                    )}

                    {/* Empty state */}
                    {total === 0 && (
                        <div className="flex flex-col items-center justify-center py-24 text-center">
                            <div className="w-16 h-16 rounded-2xl bg-slate-900 border border-slate-800 flex items-center justify-center mb-4">
                                <Server className="w-8 h-8 text-slate-600" />
                            </div>
                            <h3 className="text-lg font-semibold text-slate-300 mb-2">Нет серверов</h3>
                            <p className="text-sm text-slate-500 max-w-sm mb-6">
                                Добавьте первый VPS, чтобы начать мониторинг доступности.
                            </p>
                            <button
                                onClick={() => setAddModalOpen(true)}
                                className="flex items-center gap-2 px-5 py-2.5 rounded-xl bg-gradient-to-r from-blue-600 to-cyan-600 hover:from-blue-500 hover:to-cyan-500 text-white font-semibold transition-all text-sm shadow-lg shadow-blue-500/20"
                            >
                                <PlusCircle className="w-4 h-4" />
                                Добавить первый VPS
                            </button>
                        </div>
                    )}

                    {/* VPS list */}
                    {total > 0 && (
                        <div className="space-y-3">
                            {vpsList.map((vps) => {
                                const isChecking = checkingId === vps.id;
                                return (
                                    <div
                                        key={vps.id}
                                        className={`bg-slate-900/70 border rounded-2xl p-5 backdrop-blur-md transition-all ${
                                            vps.status === 'offline'
                                                ? 'border-rose-500/30 shadow-rose-500/5 shadow-md'
                                                : vps.status === 'online'
                                                ? 'border-emerald-500/20 hover:border-emerald-500/30'
                                                : 'border-slate-800 hover:border-slate-700'
                                        } ${!vps.enabled ? 'opacity-50' : ''}`}
                                    >
                                        <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                                            {/* Left: info */}
                                            <div className="flex-1 min-w-0">
                                                <div className="flex items-center gap-3 mb-2 flex-wrap">
                                                    <h3 className="font-bold text-slate-100 text-base">{vps.name}</h3>
                                                    <StatusBadge status={vps.status} />
                                                    {!vps.enabled && (
                                                        <span className="text-xs px-2 py-0.5 rounded bg-slate-800 text-slate-500 border border-slate-700 font-mono">
                                                            disabled
                                                        </span>
                                                    )}
                                                </div>

                                                <div className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-400 font-mono">
                                                    <span className="flex items-center gap-1">
                                                        <Globe className="w-3 h-3" />
                                                        {vps.ip_address}
                                                    </span>
                                                    {vps.hostname && (
                                                        <span className="flex items-center gap-1">
                                                            <Shield className="w-3 h-3" />
                                                            {vps.hostname}
                                                        </span>
                                                    )}
                                                    <span className="flex items-center gap-1">
                                                        <Zap className="w-3 h-3" />
                                                        :{vps.check_port}
                                                    </span>
                                                    {vps.last_response_ms != null && (
                                                        <span className={`flex items-center gap-1 ${
                                                            vps.status === 'online' ? 'text-emerald-400/80' : 'text-slate-500'
                                                        }`}>
                                                            <Zap className="w-3 h-3" />
                                                            {vps.last_response_ms} ms
                                                        </span>
                                                    )}
                                                </div>

                                                {vps.description && (
                                                    <p className="text-xs text-slate-500 mt-2 leading-relaxed">
                                                        {vps.description}
                                                    </p>
                                                )}

                                                <div className="flex items-center gap-1 text-xs text-slate-600 font-mono mt-2">
                                                    <Clock className="w-3 h-3" />
                                                    Проверен: {formatCheckedAt(vps.last_checked_at)}
                                                </div>
                                            </div>

                                            {/* Right: action buttons */}
                                            <div className="shrink-0 flex items-center gap-2">
                                                <button
                                                    onClick={() => handleCheck(vps)}
                                                    disabled={isChecking}
                                                    className="flex items-center gap-2 px-4 py-2 rounded-xl border border-slate-700 text-slate-300 hover:text-white hover:border-slate-600 hover:bg-slate-800/60 disabled:opacity-40 disabled:cursor-not-allowed transition-all text-sm font-medium"
                                                    title={`Проверить ${vps.name}`}
                                                >
                                                    {isChecking ? (
                                                        <RefreshCw className="w-4 h-4 animate-spin text-cyan-400" />
                                                    ) : (
                                                        <RefreshCw className="w-4 h-4" />
                                                    )}
                                                    Проверить
                                                </button>
                                                <button
                                                    onClick={() => setEditVps(vps)}
                                                    className="p-2 rounded-xl border border-slate-700 text-slate-400 hover:text-cyan-400 hover:border-cyan-500/40 hover:bg-cyan-500/5 transition-all"
                                                    title={`Редактировать ${vps.name}`}
                                                    aria-label={`Редактировать ${vps.name}`}
                                                >
                                                    <Pencil className="w-4 h-4" />
                                                </button>
                                                <button
                                                    onClick={() => setDeleteVps(vps)}
                                                    className="p-2 rounded-xl border border-slate-700 text-slate-400 hover:text-rose-400 hover:border-rose-500/40 hover:bg-rose-500/5 transition-all"
                                                    title={`Удалить ${vps.name}`}
                                                    aria-label={`Удалить ${vps.name}`}
                                                >
                                                    <Trash2 className="w-4 h-4" />
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    )}
                </main>
            </div>

            <AddVpsModal isOpen={addModalOpen} onClose={() => setAddModalOpen(false)} />
            <EditVpsModal vps={editVps} onClose={() => setEditVps(null)} />
            <DeleteConfirmModal vps={deleteVps} onClose={() => setDeleteVps(null)} />
        </div>
    );
}
