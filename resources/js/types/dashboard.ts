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
    hostname: string | null;
    status: 'online' | 'offline' | 'unknown';
    check_port: number;
    last_checked_at: string | null;
    response_ms: number | null;
}

export interface WebsiteItem {
    id: string;
    name: string;
    url: string;
    status: 'online' | 'offline' | 'unknown';
    last_http_status: number | null;
    last_response_ms: number | null;
    last_checked_at: string | null;
    type: 'website' | 'wordpress';
}

export interface InfrastructureItem {
    id: string;
    name: string;
    type: string;
    details: string;
    status: 'ok' | 'warning' | 'critical';
}

export interface DashboardData {
    localDevices: { total: number; online: number; offline: number; unknown: number };
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
