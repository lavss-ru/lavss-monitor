import React, { useState } from 'react';
import { Head, useForm, usePage } from '@inertiajs/react';
import { Sidebar } from '@/Components/Dashboard/Sidebar';
import { Header } from '@/Components/Dashboard/Header';

type MonitorType = 'vps' | 'website' | 'local_device' | 'location' | 'proxmox_connection' | 'proxmox_node' | 'proxmox_guest';
type Rule = { down_enabled: boolean; recovery_enabled: boolean; confirmation_seconds: number };
type Settings = { notifications_enabled: boolean; max_enabled: boolean; max_recipient_id: string;
    timezone: string; quiet_hours_enabled: boolean; quiet_hours_start: string; quiet_hours_end: string };
type Props = { settings: Settings; rules: Record<MonitorType, Rule>; maxStatus: string;
    effectiveRecipient: string | null; timezones: string[] };
const input = 'mt-1 block w-full rounded-lg border-slate-700 bg-slate-950 text-slate-100';
const sections: [MonitorType, string][] = [['vps', 'Правила VPS'], ['website', 'Правила сайтов'], ['local_device', 'Правила устройств'], ['location', 'Правила площадок'], ['proxmox_connection', 'Proxmox connection'], ['proxmox_node', 'Proxmox node'], ['proxmox_guest', 'Proxmox guest']];

export default function Notifications({ settings, rules, maxStatus, effectiveRecipient, timezones }: Props) {
    const [mobileOpen, setMobileOpen] = useState(false);
    const page = usePage();
    const form = useForm({ ...settings, rules });
    const errors = form.errors as Record<string, string>;
    const error = (key: string) => errors[key] ? <p id={`${key}-error`} role="alert" className="mt-1 text-sm text-rose-400">{errors[key]}</p> : null;
    const checkbox = (label: string, checked: boolean, change: (value: boolean) => void) =>
        <label className="flex items-center gap-3"><input type="checkbox" checked={checked} onChange={e => change(e.target.checked)} className="rounded border-slate-600 bg-slate-950" />{label}</label>;
    const ruleChange = (type: MonitorType, next: Partial<Rule>) => form.setData('rules', { ...form.data.rules, [type]: { ...form.data.rules[type], ...next } });
    return <div className="min-h-screen bg-slate-950 text-slate-100">
        <Head title="Настройки уведомлений" />
        <Sidebar mobileOpen={mobileOpen} setMobileOpen={setMobileOpen} />
        <div className="md:pl-64">
            <Header statusTitle="Настройки уведомлений" overallStatus="ok" userName={page.props.auth.user.name} onMenuToggle={() => setMobileOpen(true)} />
            <main className="mx-auto max-w-4xl p-4 md:p-8">
                <h1 className="text-2xl font-bold">Настройки мониторинга и уведомлений</h1>
                <p className="mt-2 text-sm text-slate-400">Изменения применяются со следующего цикла проверки. История проверок и события сохраняются независимо от отправки уведомлений.</p>
                <form className="mt-6 space-y-5" onSubmit={e => { e.preventDefault(); form.put('/settings/notifications', { preserveScroll: true }); }}>
                    <fieldset disabled={form.processing} className="space-y-5 disabled:opacity-60">
                        <section className="space-y-4 rounded-xl border border-slate-800 bg-slate-900 p-5">
                            <h2 className="text-lg font-semibold">Общие</h2>
                            {checkbox('Уведомления включены', form.data.notifications_enabled, value => form.setData('notifications_enabled', value))}
                            {error('notifications_enabled')}
                            <label className="block">Часовой пояс<select className={input} value={form.data.timezone} onChange={e => form.setData('timezone', e.target.value)}>{timezones.map(zone => <option key={zone}>{zone}</option>)}</select></label>
                            {error('timezone')}
                        </section>
                        <section className="space-y-4 rounded-xl border border-slate-800 bg-slate-900 p-5">
                            <h2 className="text-lg font-semibold">MAX</h2>
                            {checkbox('Отправлять в MAX', form.data.max_enabled, value => form.setData('max_enabled', value))}{error('max_enabled')}
                            <label className="block">ID получателя MAX<input className={input} maxLength={255} value={form.data.max_recipient_id} onChange={e => form.setData('max_recipient_id', e.target.value)} /></label>{error('max_recipient_id')}
                            <p className="text-sm text-slate-400">Один получатель. Если поле пустое, используется получатель из конфигурации сервера.</p>
                            <p className="text-sm">{maxStatus}. Получатель: {effectiveRecipient ?? 'не указан'}.</p>
                        </section>
                        <section className="space-y-4 rounded-xl border border-slate-800 bg-slate-900 p-5">
                            <h2 className="text-lg font-semibold">Тихие часы</h2>
                            {checkbox('Включить тихие часы', form.data.quiet_hours_enabled, value => form.setData('quiet_hours_enabled', value))}{error('quiet_hours_enabled')}
                            <div className="grid gap-4 sm:grid-cols-2">{(['quiet_hours_start', 'quiet_hours_end'] as const).map((key, i) => <div key={key}><label className="block">{i === 0 ? 'Начало' : 'Конец'}<input type="time" required={form.data.quiet_hours_enabled} className={input} value={form.data[key]} onChange={e => form.setData(key, e.target.value)} /></label>{error(key)}</div>)}</div>
                            <p className="text-sm text-slate-400">По выбранному часовому поясу, включая интервалы через полночь. После тихих часов уведомление о сбое отправляется, если сбой ещё продолжается. Восстановление отправляется только после доставленного уведомления о сбое.</p>
                        </section>
                        {sections.map(([type, title]) => <section key={type} className="space-y-4 rounded-xl border border-slate-800 bg-slate-900 p-5">
                            <h2 className="text-lg font-semibold">{title}</h2>
                            {checkbox('Уведомлять о недоступности (DOWN)', form.data.rules[type].down_enabled, value => ruleChange(type, { down_enabled: value }))}{error(`rules.${type}.down_enabled`)}
                            {checkbox('Уведомлять о восстановлении (Recovery)', form.data.rules[type].recovery_enabled, value => ruleChange(type, { recovery_enabled: value }))}{error(`rules.${type}.recovery_enabled`)}
                            <label className="block">Задержка подтверждения сбоя, секунд<input required type="number" min={0} max={86400} step={1} className={input} value={form.data.rules[type].confirmation_seconds} onChange={e => ruleChange(type, { confirmation_seconds: Number(e.target.value) })} /></label>{error(`rules.${type}.confirmation_seconds`)}
                            <p className="text-sm text-slate-400">От 0 до 86400 секунд. 0 — подтверждать при первой неуспешной проверке.</p>
                        </section>)}
                    </fieldset>
                    {Object.entries(errors).filter(([key]) => key === 'rules' || sections.some(([type]) => key === `rules.${type}`)).map(([key, value]) => <p role="alert" key={key} className="text-rose-400">{value}</p>)}
                    <div className="flex items-center gap-4"><button type="submit" disabled={form.processing} className="rounded-lg bg-blue-600 px-5 py-3 font-medium hover:bg-blue-500 disabled:opacity-50">{form.processing ? 'Сохранение…' : 'Сохранить настройки'}</button>{form.recentlySuccessful && <p role="status" className="text-emerald-400">Настройки сохранены.</p>}</div>
                </form>
            </main>
        </div>
    </div>;
}
