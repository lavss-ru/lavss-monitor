export interface WebsiteRecord {
    id: number;
    name: string;
    url: string;
    type: 'website' | 'wordpress';
    enabled: boolean;
    description: string | null;
    status: 'online' | 'offline' | 'unknown';
    last_http_status: number | null;
    last_response_ms: number | null;
    last_checked_at: string | null;
}
