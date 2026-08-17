<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Display the main lavss monitor Dashboard with mock data for Stage 2.
     */
    public function index(Request $request): Response
    {
        $mockData = [
            'overallStatus' => 'warning',
            'statusTitle' => '🟡 Требуют внимания — 2',
            'statusSubtitle' => 'Инфраструктура функционирует, выявлено 2 предупреждения.',
            'summaries' => [
                'vps' => ['count' => 3, 'label' => 'VPS', 'sub' => 'Все на связи'],
                'websites' => ['count' => 15, 'label' => 'Сайты', 'sub' => '1 с предупреждением'],
                'wordpress' => ['count' => 15, 'label' => 'WordPress', 'sub' => '15 активных сайтов'],
                'infrastructure' => ['count' => 6, 'label' => 'Инфраструктура', 'sub' => '2 PVE, 2 Свервера, 2 LXC'],
            ],
            'attentionItems' => [
                [
                    'id' => 'att-1',
                    'title' => 'site3.ru',
                    'description' => 'SSL-сертификат истекает через 3 дня (2026-08-19)',
                    'level' => 'warning',
                    'category' => 'Website',
                ],
                [
                    'id' => 'att-2',
                    'title' => 'VPS-03',
                    'description' => 'Заполнение основного диска превысило порог: 87%',
                    'level' => 'warning',
                    'category' => 'VPS',
                ],
            ],
            'recentEvents' => [
                [
                    'id' => 'evt-1',
                    'title' => 'site1.ru',
                    'message' => 'HTTP 200 OK — время отклика 98ms',
                    'time' => '10 мин назад',
                    'type' => 'success',
                ],
                [
                    'id' => 'evt-2',
                    'title' => 'PVE Home',
                    'message' => 'Автоматический бэкап VM/LXC успешно завершён',
                    'time' => '35 мин назад',
                    'type' => 'info',
                ],
                [
                    'id' => 'evt-3',
                    'title' => 'VPS-03',
                    'message' => 'Предупреждение: Высокий процент использования диска (87%)',
                    'time' => '1 час назад',
                    'type' => 'warning',
                ],
                [
                    'id' => 'evt-4',
                    'title' => 'Home Seafile',
                    'message' => 'Контейнер LXC активно передаёт данные (Network Out 4.2 MB/s)',
                    'time' => '2 часа назад',
                    'type' => 'info',
                ],
            ],
            'vpsList' => [
                ['id' => 'vps-1', 'name' => 'VPS-01', 'ip' => '185.22.x.1', 'cpu' => 12, 'ram' => 42, 'disk' => 48, 'load' => '0.15', 'uptime' => '42 дн.', 'status' => 'ok'],
                ['id' => 'vps-2', 'name' => 'VPS-02', 'ip' => '185.22.x.2', 'cpu' => 28, 'ram' => 64, 'disk' => 55, 'load' => '0.42', 'uptime' => '108 дн.', 'status' => 'ok'],
                ['id' => 'vps-3', 'name' => 'VPS-03', 'ip' => '185.22.x.3', 'cpu' => 45, 'ram' => 78, 'disk' => 87, 'load' => '1.12', 'uptime' => '15 дн.', 'status' => 'warning'],
            ],
            'websitesList' => [
                ['id' => 'web-1', 'name' => 'oskolzoo.ru', 'url' => 'https://oskolzoo.ru', 'status' => '200 OK', 'responseTime' => '120 ms', 'sslDays' => 240, 'type' => 'WordPress', 'state' => 'ok'],
                ['id' => 'web-2', 'name' => 'cbosgo.ru', 'url' => 'https://cbosgo.ru', 'status' => '200 OK', 'responseTime' => '145 ms', 'sslDays' => 180, 'type' => 'WordPress', 'state' => 'ok'],
                ['id' => 'web-3', 'name' => 'site1.ru', 'url' => 'https://site1.ru', 'status' => '200 OK', 'responseTime' => '98 ms', 'sslDays' => 90, 'type' => 'WordPress', 'state' => 'ok'],
                ['id' => 'web-4', 'name' => 'site2.ru', 'url' => 'https://site2.ru', 'status' => '200 OK', 'responseTime' => '210 ms', 'sslDays' => 115, 'type' => 'WordPress', 'state' => 'ok'],
                ['id' => 'web-5', 'name' => 'site3.ru', 'url' => 'https://site3.ru', 'status' => '200 OK', 'responseTime' => '340 ms', 'sslDays' => 3, 'type' => 'WordPress', 'state' => 'warning'],
            ],
            'infrastructureList' => [
                ['id' => 'inf-1', 'name' => 'PVE Home', 'type' => 'Proxmox VE Node', 'details' => 'CPU 18% | RAM 52% | Storage 40%', 'status' => 'ok'],
                ['id' => 'inf-2', 'name' => 'PVE ЦБО', 'type' => 'Proxmox VE Node', 'details' => 'CPU 24% | RAM 68% | Storage 62%', 'status' => 'ok'],
                ['id' => 'inf-3', 'name' => 'SRV1 ЦБО', 'type' => 'Linux Server', 'details' => 'Standby mode', 'status' => 'ok'],
                ['id' => 'inf-4', 'name' => 'SRV2 ЦБО', 'type' => 'Linux Server', 'details' => 'Active node', 'status' => 'ok'],
                ['id' => 'inf-5', 'name' => 'Home Backup', 'type' => 'LXC Container', 'details' => 'PVE Home / Host ID 101', 'status' => 'ok'],
                ['id' => 'inf-6', 'name' => 'Home Seafile', 'type' => 'LXC Container', 'details' => 'PVE Home / Host ID 102', 'status' => 'ok'],
            ],
        ];

        return Inertia::render('Dashboard', [
            'dashboard' => $mockData,
        ]);
    }
}
