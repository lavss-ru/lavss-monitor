export interface SummaryItem {
    count: number;
    label: string;
    sub: string;
}

export interface AttentionItem {
    id: string;
    title: string;
    description: string;
    level: 'warning' | 'critical' | 'info';
    category: string;
}

export interface RecentEvent {
    id: string;
    title: string;
    message: string;
    time: string;
    type: 'success' | 'warning' | 'info' | 'error';
}

export interface VPSItem {
    id: string;
    name: string;
    ip: string;
    cpu: number;
    ram: number;
    disk: number;
    load: string;
    uptime: string;
    status: 'ok' | 'warning' | 'critical';
}

export interface WebsiteItem {
    id: string;
    name: string;
    url: string;
    status: string;
    responseTime: string;
    sslDays: number;
    type: string;
    state: 'ok' | 'warning' | 'critical';
}

export interface InfrastructureItem {
    id: string;
    name: string;
    type: string;
    details: string;
    status: 'ok' | 'warning' | 'critical';
}

export interface DashboardData {
    overallStatus: 'ok' | 'warning' | 'critical';
    statusTitle: string;
    statusSubtitle: string;
    summaries: {
        vps: SummaryItem;
        websites: SummaryItem;
        wordpress: SummaryItem;
        infrastructure: SummaryItem;
    };
    attentionItems: AttentionItem[];
    recentEvents: RecentEvent[];
    vpsList: VPSItem[];
    websitesList: WebsiteItem[];
    infrastructureList: InfrastructureItem[];
}
