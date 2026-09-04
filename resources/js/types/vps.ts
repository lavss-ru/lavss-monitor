/**
 * Full VPS record as returned by VpsController@index (Vps/Index page).
 */
export interface VpsRecord {
    id: number;
    name: string;
    hostname: string | null;
    ip_address: string;
    description: string | null;
    enabled: boolean;
    status: 'online' | 'offline' | 'unknown';
    check_port: number;
    last_checked_at: string | null;
    last_response_ms: number | null;
}
