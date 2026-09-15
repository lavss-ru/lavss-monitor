import React from 'react';

type Props = {
    connection_type: string;
    wireguard_interface: string | null;
    wireguard_diagnostic_state: string;
    wireguard_last_handshake_at: string | null;
    wireguard_rx_bytes: number | null;
    wireguard_tx_bytes: number | null;
};

export default function WireGuardDiagnostics(location: Props) {
    if (location.connection_type !== 'wireguard' || !location.wireguard_interface) return null;

    const labels: Record<string, string> = {
        fresh: 'свежий handshake', stale: 'давний handshake', never: 'handshake не было',
    };
    const label = labels[location.wireguard_diagnostic_state];

    return <p className="mt-1 text-xs text-slate-400">
        WG: {location.wireguard_interface} · {label ? <>
            Диагностика: {label}
            {location.wireguard_last_handshake_at && ` · Handshake: ${Math.max(0, Math.floor((Date.now() - new Date(location.wireguard_last_handshake_at).getTime()) / 1000))} сек назад`}
            {location.wireguard_rx_bytes !== null && ` · RX/TX: ${location.wireguard_rx_bytes}/${location.wireguard_tx_bytes} B`}
        </> : 'WG-диагностика недоступна. Доступность площадки определяется TCP-проверкой.'}
    </p>;
}
