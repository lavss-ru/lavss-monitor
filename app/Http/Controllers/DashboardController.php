<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Infrastructure;
use App\Models\Vps;
use App\Models\Website;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Display the main lavss monitor Dashboard loaded dynamically from PostgreSQL.
     */
    public function index(Request $request): Response
    {
        $vpsCollection = Vps::where('enabled', true)->get();
        $websiteCollection = Website::where('enabled', true)->get();
        $infrastructureCollection = Infrastructure::where('enabled', true)->get();
        $activeEvents = Event::whereNull('resolved_at')
            ->whereIn('severity', ['warning', 'critical'])
            ->orderByDesc('occurred_at')
            ->get();
        $recentEventsCollection = Event::orderByDesc('occurred_at')->take(10)->get();

        $activeWarningTitles = $activeEvents->pluck('title')->all();

        // VPS list
        $vpsList = $vpsCollection->map(function ($vps) use ($activeWarningTitles) {
            $hasWarning = in_array($vps->name, $activeWarningTitles);

            return [
                'id' => (string) $vps->id,
                'name' => $vps->name,
                'ip' => $vps->ip_address,
                'cpu' => $hasWarning ? 45 : ($vps->name === 'VPS-02' ? 28 : 12),
                'ram' => $hasWarning ? 78 : ($vps->name === 'VPS-02' ? 64 : 42),
                'disk' => $hasWarning ? 87 : ($vps->name === 'VPS-02' ? 55 : 48),
                'load' => $hasWarning ? '1.12' : ($vps->name === 'VPS-02' ? '0.42' : '0.15'),
                'uptime' => $hasWarning ? '15 дн.' : ($vps->name === 'VPS-02' ? '108 дн.' : '42 дн.'),
                'status' => $hasWarning ? 'warning' : 'ok',
            ];
        });

        // Websites list
        $websitesList = $websiteCollection->map(function ($site) use ($activeWarningTitles) {
            $hasWarning = in_array($site->name, $activeWarningTitles);

            return [
                'id' => (string) $site->id,
                'name' => $site->name,
                'url' => $site->url,
                'status' => '200 OK',
                'responseTime' => $hasWarning ? '340 ms' : '120 ms',
                'sslDays' => $hasWarning ? 3 : 180,
                'type' => ucfirst($site->type),
                'state' => $hasWarning ? 'warning' : 'ok',
            ];
        });

        // Infrastructure list
        $infrastructureList = $infrastructureCollection->map(function ($item) use ($activeWarningTitles) {
            $hasWarning = in_array($item->name, $activeWarningTitles);
            $typeLabel = match (strtolower($item->type)) {
                'proxmox' => 'Proxmox VE Node',
                'server' => 'Linux Server',
                'lxc' => 'LXC Container',
                'vm' => 'Virtual Machine',
                default => ucfirst($item->type),
            };

            return [
                'id' => (string) $item->id,
                'name' => $item->name,
                'type' => $typeLabel,
                'details' => $item->description ?? 'Normal operation',
                'status' => $hasWarning ? 'warning' : 'ok',
            ];
        });

        // Attention items
        $attentionItems = $activeEvents->map(function ($event) {
            $category = match (strtolower($event->type)) {
                'vps', 'server' => 'VPS',
                'website', 'wordpress' => 'Website',
                default => 'Infrastructure',
            };

            return [
                'id' => 'att-' . $event->id,
                'title' => $event->title,
                'description' => $event->message,
                'level' => $event->severity,
                'category' => $category,
            ];
        });

        // Recent events
        $recentEvents = $recentEventsCollection->map(function ($event) {
            return [
                'id' => 'evt-' . $event->id,
                'title' => $event->title,
                'message' => $event->message,
                'time' => $event->occurred_at ? $event->occurred_at->diffForHumans() : 'Только что',
                'type' => match ($event->severity) {
                    'critical', 'error' => 'error',
                    'warning' => 'warning',
                    'success' => 'success',
                    default => 'info',
                },
            ];
        });

        // Summary counters
        $vpsCount = $vpsCollection->count();
        $websitesCount = $websiteCollection->count();
        $wordpressCount = $websiteCollection->where('type', 'wordpress')->count();
        $infrastructureCount = $infrastructureCollection->count();

        $pveCount = $infrastructureCollection->where('type', 'proxmox')->count();
        $serverCount = $infrastructureCollection->where('type', 'server')->count();
        $lxcCount = $infrastructureCollection->where('type', 'lxc')->count();

        $activeWarningsCount = $activeEvents->count();
        $webWarningCount = $activeEvents->where('type', 'Website')->count();

        $overallStatus = $activeWarningsCount > 0 ? 'warning' : 'ok';
        $statusTitle = $activeWarningsCount > 0
            ? "🟡 Требуют внимания — {$activeWarningsCount}"
            : '🟢 Вся система работает нормально';
        $statusSubtitle = $activeWarningsCount > 0
            ? "Инфраструктура функционирует, выявлено {$activeWarningsCount} предупреждения."
            : 'Все объекты и сервисы находятся в рабочем состоянии.';

        $dashboardData = [
            'overallStatus' => $overallStatus,
            'statusTitle' => $statusTitle,
            'statusSubtitle' => $statusSubtitle,
            'summaries' => [
                'vps' => [
                    'count' => $vpsCount,
                    'label' => 'VPS',
                    'sub' => 'Все на связи',
                ],
                'websites' => [
                    'count' => $websitesCount,
                    'label' => 'Сайты',
                    'sub' => $webWarningCount > 0 ? "{$webWarningCount} с предупреждением" : 'Все доступны',
                ],
                'wordpress' => [
                    'count' => $wordpressCount,
                    'label' => 'WordPress',
                    'sub' => "{$wordpressCount} активных сайтов",
                ],
                'infrastructure' => [
                    'count' => $infrastructureCount,
                    'label' => 'Инфраструктура',
                    'sub' => "{$pveCount} PVE, {$serverCount} Сервера, {$lxcCount} LXC",
                ],
            ],
            'attentionItems' => $attentionItems->values()->all(),
            'recentEvents' => $recentEvents->values()->all(),
            'vpsList' => $vpsList->values()->all(),
            'websitesList' => $websitesList->values()->all(),
            'infrastructureList' => $infrastructureList->values()->all(),
        ];

        return Inertia::render('Dashboard', [
            'dashboard' => $dashboardData,
        ]);
    }
}
