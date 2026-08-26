<?php

namespace Database\Seeders;

use App\Models\Event;
use App\Models\Infrastructure;
use App\Models\Vps;
use App\Models\Website;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class DemoSeeder extends Seeder
{
    /**
     * Seed demonstration data for lavss monitor.
     */
    public function run(): void
    {
        // 1. VPS / Servers (3 total)
        Vps::updateOrCreate(
            ['name' => 'VPS-01'],
            [
                'hostname' => 'vps1.lavss.ru',
                'ip_address' => '185.22.x.1',
                'description' => 'Основной нод разработки',
                'enabled' => true,
            ]
        );

        Vps::updateOrCreate(
            ['name' => 'VPS-02'],
            [
                'hostname' => 'vps2.lavss.ru',
                'ip_address' => '185.22.x.2',
                'description' => 'Вспомогательный шлюз',
                'enabled' => true,
            ]
        );

        Vps::updateOrCreate(
            ['name' => 'VPS-03'],
            [
                'hostname' => 'vps3.lavss.ru',
                'ip_address' => '185.22.x.3',
                'description' => 'Заполнение основного диска превысило порог: 87%',
                'enabled' => true,
            ]
        );

        // 2. Infrastructure (6 total)
        Infrastructure::updateOrCreate(
            ['name' => 'PVE Home'],
            [
                'type' => 'proxmox',
                'description' => 'CPU 18% | RAM 52% | Storage 40%',
                'enabled' => true,
            ]
        );

        Infrastructure::updateOrCreate(
            ['name' => 'PVE ЦБО'],
            [
                'type' => 'proxmox',
                'description' => 'CPU 24% | RAM 68% | Storage 62%',
                'enabled' => true,
            ]
        );

        Infrastructure::updateOrCreate(
            ['name' => 'SRV1 ЦБО'],
            [
                'type' => 'server',
                'description' => 'Standby mode',
                'enabled' => true,
            ]
        );

        Infrastructure::updateOrCreate(
            ['name' => 'SRV2 ЦБО'],
            [
                'type' => 'server',
                'description' => 'Active node',
                'enabled' => true,
            ]
        );

        Infrastructure::updateOrCreate(
            ['name' => 'Home Backup'],
            [
                'type' => 'lxc',
                'description' => 'PVE Home / Host ID 101',
                'enabled' => true,
            ]
        );

        Infrastructure::updateOrCreate(
            ['name' => 'Home Seafile'],
            [
                'type' => 'lxc',
                'description' => 'PVE Home / Host ID 102',
                'enabled' => true,
            ]
        );

        // 3. Websites (15 total, all type wordpress)
        $websites = [
            ['name' => 'oskolzoo.ru', 'url' => 'https://oskolzoo.ru'],
            ['name' => 'cbosgo.ru', 'url' => 'https://cbosgo.ru'],
            ['name' => 'site1.ru', 'url' => 'https://site1.ru'],
            ['name' => 'site2.ru', 'url' => 'https://site2.ru'],
            ['name' => 'site3.ru', 'url' => 'https://site3.ru', 'description' => 'SSL истекает через 3 дня'],
        ];

        for ($i = 4; $i <= 13; $i++) {
            $websites[] = [
                'name' => "site{$i}.ru",
                'url' => "https://site{$i}.ru",
            ];
        }

        foreach ($websites as $site) {
            Website::updateOrCreate(
                ['url' => $site['url']],
                [
                    'name' => $site['name'],
                    'type' => 'wordpress',
                    'enabled' => true,
                    'description' => $site['description'] ?? null,
                ]
            );
        }

        // 4. Events
        $now = Carbon::now();

        Event::updateOrCreate(
            [
                'title' => 'site3.ru',
                'message' => 'SSL-сертификат истекает через 3 дня (2026-08-19)',
            ],
            [
                'type' => 'Website',
                'severity' => 'warning',
                'occurred_at' => $now->copy()->subHours(2),
                'resolved_at' => null,
            ]
        );

        Event::updateOrCreate(
            [
                'title' => 'VPS-03',
                'message' => 'Заполнение основного диска превысило порог: 87%',
            ],
            [
                'type' => 'VPS',
                'severity' => 'warning',
                'occurred_at' => $now->copy()->subHour(),
                'resolved_at' => null,
            ]
        );

        Event::updateOrCreate(
            [
                'title' => 'site1.ru',
                'message' => 'HTTP 200 OK — время отклика 98ms',
            ],
            [
                'type' => 'Website',
                'severity' => 'success',
                'occurred_at' => $now->copy()->subMinutes(10),
                'resolved_at' => $now->copy()->subMinutes(10),
            ]
        );

        Event::updateOrCreate(
            [
                'title' => 'PVE Home',
                'message' => 'Автоматический бэкап VM/LXC успешно завершён',
            ],
            [
                'type' => 'Infrastructure',
                'severity' => 'info',
                'occurred_at' => $now->copy()->subMinutes(35),
                'resolved_at' => $now->copy()->subMinutes(35),
            ]
        );

        Event::updateOrCreate(
            [
                'title' => 'Home Seafile',
                'message' => 'Контейнер LXC активно передаёт данные (Network Out 4.2 MB/s)',
            ],
            [
                'type' => 'Infrastructure',
                'severity' => 'info',
                'occurred_at' => $now->copy()->subHours(2),
                'resolved_at' => $now->copy()->subHours(2),
            ]
        );
    }
}
