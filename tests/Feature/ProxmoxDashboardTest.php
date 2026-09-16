<?php

use App\Models\User;
use App\Services\ProxmoxSummaryService;
use App\Services\ProxmoxSyncService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/../Support/Proxmox.php';

test('proxmox dashboard uses real inventory excludes templates stale and disabled connections', function () {
    $c = pveConnection();
    pveFake();
    app(ProxmoxSyncService::class)->run($c);
    $c->guests()->create(['guest_type' => 'qemu', 'vmid' => 200, 'status' => 'running', 'template' => true]);
    $c->guests()->create(['guest_type' => 'lxc', 'vmid' => 201, 'status' => 'running', 'stale' => true]);
    $c->guests()->create(['guest_type' => 'qemu', 'vmid' => 202, 'status' => 'stopped']);
    $c->guests()->create(['guest_type' => 'lxc', 'vmid' => 203, 'status' => 'running']);
    $disabled = pveConnection(['enabled' => false]);
    $disabled->nodes()->create(['node_name' => 'disabled']);
    $disabled->guests()->create(['guest_type' => 'qemu', 'vmid' => 204, 'status' => 'running']);
    $c->nodes()->create(['node_name' => 'stale', 'stale' => true]);
    pveFake();
    $this->actingAs(User::factory()->create())->get('/')->assertInertia(fn ($page) => $page
        ->where('dashboard.proxmox.connections', 1)->where('dashboard.proxmox.nodes', 2)
        ->where('dashboard.proxmox.vm', ['total' => 2, 'running' => 1, 'stopped' => 1])
        ->where('dashboard.proxmox.lxc', ['total' => 2, 'running' => 1, 'stopped' => 1]));
    Http::assertNothingSent();
});

test('proxmox dashboard retains totals but suppresses running stopped when unavailable', function () {
    $c = pveConnection();
    pveFake();
    app(ProxmoxSyncService::class)->run($c);
    $c->location->update(['monitoring_enabled' => true, 'probe_host' => '192.0.2.1', 'probe_port' => 22, 'status' => 'unknown']);
    $summary = app(ProxmoxSummaryService::class)->summary();
    expect($summary['vm'])->toBe(['total' => 1, 'running' => 0, 'stopped' => 0])
        ->and($summary['lxc'])->toBe(['total' => 1, 'running' => 0, 'stopped' => 0]);
});

test('proxmox scheduler is every minute with overlap protection', function () {
    app(Kernel::class)->all();
    $event = collect(app(Schedule::class)->events())->sole(fn ($e) => str_contains($e->command ?? '', 'monitor:proxmox'));
    expect($event->expression)->toBe('* * * * *')->and($event->withoutOverlapping)->toBeTrue()->and($event->expiresAt)->toBe(10);
});

test('proxmox command isolates failures and skips unavailable connections', function () {
    $a = pveConnection(['host' => 'bad.example.test']);
    $b = pveConnection();
    $c = pveConnection(['enabled' => false]);
    $d = pveConnection();
    $d->location->update(['enabled' => false]);
    localHttpFake([
        'https://bad.example.test:*' => Http::response('', 401),
        '*/nodes' => Http::response(['data' => [['node' => 'pve1'], ['node' => 'pve2']]]),
        '*/cluster/resources' => Http::response(['data' => pveResources()]),
    ]);
    $this->artisan('monitor:proxmox')->expectsOutput('Proxmox: checked=2, errors=1, skipped=1')->assertExitCode(1);
    expect($a->fresh()->status)->toBe('unknown')->and($b->fresh()->status)->toBe('online')
        ->and($c->fresh()->status)->toBe('unknown')->and($d->fresh()->status)->toBe('unknown');
    Http::assertSentCount(3);
});
