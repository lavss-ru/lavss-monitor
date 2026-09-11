import React, { useState } from 'react';
import { Head, usePage } from '@inertiajs/react';
import { Sidebar } from '@/Components/Dashboard/Sidebar';
import { Header } from '@/Components/Dashboard/Header';
import { SummaryCards } from '@/Components/Dashboard/SummaryCards';
import { AttentionSection, RecentEventsSection } from '@/Components/Dashboard/AttentionSection';
import { VpsSection } from '@/Components/Dashboard/VpsSection';
import { WebsitesSection } from '@/Components/Dashboard/WebsitesSection';
import { InfrastructureSection, QuickActionsModal } from '@/Components/Dashboard/InfrastructureSection';
import { DashboardData } from '@/types/dashboard';

interface DashboardProps {
    dashboard: DashboardData;
}

export default function Dashboard({ dashboard }: DashboardProps) {
    const user = usePage().props.auth.user;
    const [mobileOpen, setMobileOpen] = useState(false);
    const [addModalOpen, setAddModalOpen] = useState(false);

    return (
        <div className="min-h-screen bg-slate-950 text-slate-100 flex flex-col font-sans antialiased">
            <Head title="Dashboard — lavss monitor" />

            {/* Sidebar Navigation */}
            <Sidebar
                mobileOpen={mobileOpen}
                setMobileOpen={setMobileOpen}
                onAddObjectClick={() => setAddModalOpen(true)}
            />

            {/* Main Content Workspace */}
            <div className="md:pl-64 flex flex-col flex-1 min-w-0">
                {/* Top Header */}
                <Header
                    statusTitle={dashboard.statusTitle}
                    overallStatus={dashboard.overallStatus}
                    userName={user.name}
                    onMenuToggle={() => setMobileOpen(true)}
                />

                {/* Main Dashboard Canvas */}
                <main className="flex-1 p-4 sm:p-6 md:p-8 max-w-7xl w-full mx-auto">
                    {/* Status Subtitle Note */}
                    <div className="mb-6">
                        <h2 className="text-xl font-bold text-slate-100 tracking-tight">
                            Единый пульт наблюдения
                        </h2>
                        <p className="text-xs text-slate-400 font-mono mt-1">
                            {dashboard.statusSubtitle}
                        </p>
                    </div>

                    {/* 4 Summary Cards */}
                    <SummaryCards summaries={dashboard.summaries} />

                    <a href="/local-infrastructure" className="block my-6 rounded-2xl border border-slate-800 bg-slate-900/50 p-5 hover:border-cyan-700">
                        <h3 className="font-semibold">Локальная инфраструктура</h3>
                        <p className="mt-2 text-sm text-slate-400">Под мониторингом: {dashboard.localDevices.total} · <span className="text-emerald-400">Online: {dashboard.localDevices.online}</span> · <span className="text-rose-400">Offline: {dashboard.localDevices.offline}</span> · Не проверены: {dashboard.localDevices.unknown}</p>
                    </a>

                    {/* Require Attention Block */}
                    <AttentionSection items={dashboard.attentionItems} />

                    {/* Recent Events & Quick Actions grid */}
                    <RecentEventsSection events={dashboard.recentEvents} />

                    {/* VPS / Server Section */}
                    <VpsSection servers={dashboard.vpsList} />

                    {/* Websites & WordPress Section */}
                    <WebsitesSection websites={dashboard.websitesList} />

                    {/* Proxmox & Infrastructure Section */}
                    <InfrastructureSection items={dashboard.infrastructureList} />
                </main>
            </div>

            {/* Quick Action Add Object Modal */}
            <QuickActionsModal
                isOpen={addModalOpen}
                onClose={() => setAddModalOpen(false)}
            />
        </div>
    );
}
