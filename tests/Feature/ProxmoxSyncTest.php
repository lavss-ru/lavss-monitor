<?php

use App\Models\ProxmoxGuest;
use App\Services\ProxmoxSyncService;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/../Support/Proxmox.php';

test('proxmox sync creates and updates inventory including moves templates and metrics', function () {
    $c = pveConnection();
    pveFake();
    expect(app(ProxmoxSyncService::class)->run($c)['status'])->toBe('online');
    expect($c->nodes()->count())->toBe(2)->and($c->guests()->count())->toBe(2);
    $guest = $c->guests()->where('guest_type', 'qemu')->sole();
    expect($guest->node->node_name)->toBe('pve1')->and($guest->cpu_usage)->toBe(0.5);
    $rows = pveResources();
    $rows[2] = array_replace($rows[2], ['node' => 'pve2', 'status' => 'paused', 'cpu' => 0.1, 'mem' => 1024, 'template' => 1]);
    $rows[3]['status'] = 'running';
    pveFake($rows);
    app(ProxmoxSyncService::class)->run($c);
    $guest->refresh();
    expect($guest->node->node_name)->toBe('pve2')->and($guest->status)->toBe('paused')
        ->and($guest->cpu_usage)->toBe(0.1)->and($guest->memory_used)->toBe(1024)->and($guest->template)->toBeTrue()
        ->and($c->guests()->count())->toBe(2)->and($c->guests()->where('guest_type', 'lxc')->sole()->status)->toBe('running')
        ->and($c->fresh()->last_synced_at)->not->toBeNull();
    $this->assertDatabaseCount('events', 0);
    $this->assertDatabaseCount('monitor_checks', 0);
});

test('proxmox missing guest becomes stale and reappearance reuses row', function () {
    $c = pveConnection();
    pveFake();
    app(ProxmoxSyncService::class)->run($c);
    $guest = $c->guests()->where('guest_type', 'qemu')->sole();
    $seen = $guest->last_seen_at->toISOString();
    $this->travel(2)->minutes();
    $rows = pveResources();
    unset($rows[2]);
    pveFake(array_values($rows));
    app(ProxmoxSyncService::class)->run($c);
    expect($guest->fresh()->stale)->toBeTrue()->and($guest->fresh()->status)->toBe('unknown')
        ->and($guest->fresh()->last_seen_at->toISOString())->toBe($seen)->and($c->guests()->count())->toBe(2);
    pveFake();
    app(ProxmoxSyncService::class)->run($c);
    expect($guest->fresh()->stale)->toBeFalse()->and($guest->fresh()->last_seen_at->toISOString())->not->toBe($seen);
});

test('proxmox missing node stays cached when validated node set shrinks', function () {
    $c = pveConnection();
    pveFake();
    app(ProxmoxSyncService::class)->run($c);
    $node = $c->nodes()->where('node_name', 'pve2')->sole();
    localHttpFake([
        '*/nodes' => Http::response(['data' => [['node' => 'pve1']]]),
        '*/cluster/resources' => Http::response(['data' => [pveResources()[0], pveResources()[2]]]),
    ]);
    app(ProxmoxSyncService::class)->run($c);
    expect($node->fresh()->stale)->toBeTrue()->and($node->fresh()->status)->toBe('unknown')->and($c->nodes()->count())->toBe(2);
});

test('proxmox failed and malformed sync preserves all previous inventory', function (string $failure) {
    $c = pveConnection();
    pveFake();
    app(ProxmoxSyncService::class)->run($c);
    $before = [$c->nodes()->get()->toArray(), $c->guests()->get()->toArray()];
    if ($failure === 'malformed') {
        pveFake([]);
    } else {
        localHttpFake(['*' => Http::response('synthetic-test-secret', 401)]);
    }
    $this->travel(2)->minutes();
    $result = app(ProxmoxSyncService::class)->run($c);
    expect($result['status'])->toBe('unknown')->and([$c->nodes()->get()->toArray(), $c->guests()->get()->toArray()])->toBe($before);
})->with(['malformed', 'auth']);

test('proxmox respects location dependency without HTTP timestamps or stale changes', function (string $state) {
    $c = pveConnection();
    pveFake();
    app(ProxmoxSyncService::class)->run($c);
    $before = [$c->nodes()->get()->toArray(), $c->guests()->get()->toArray()];
    $checked = $c->fresh()->last_checked_at->toISOString();
    $c->location->update(['monitoring_enabled' => true, 'probe_host' => '192.0.2.1', 'probe_port' => 22,
        'status' => $state === 'disabled' ? 'online' : $state, 'enabled' => $state !== 'disabled',
        'incident_confirmed_at' => $state === 'offline' ? now() : null]);
    pveFake();
    $this->travel(2)->minutes();
    $result = app(ProxmoxSyncService::class)->run($c);
    expect($result)->toBe(['status' => 'unknown', 'code' => 'location_unavailable', 'skipped' => true])
        ->and($c->fresh()->last_checked_at->toISOString())->toBe($checked)
        ->and([$c->nodes()->get()->toArray(), $c->guests()->get()->toArray()])->toBe($before);
    Http::assertNothingSent();
})->with(['offline', 'unknown', 'disabled']);

test('proxmox allows online unmonitored and grace locations', function (string $state) {
    $c = pveConnection();
    $c->location->update(['monitoring_enabled' => $state !== 'unmonitored', 'probe_host' => '192.0.2.1',
        'probe_port' => 22, 'status' => $state === 'grace' ? 'offline' : 'online']);
    pveFake();
    expect(app(ProxmoxSyncService::class)->run($c)['status'])->toBe('online');
    Http::assertSentCount(2);
})->with(['online', 'unmonitored', 'grace']);

test('disabled proxmox connection never calls API even manually', function (bool $sync) {
    $c = pveConnection(['enabled' => false]);
    pveFake();
    expect(app(ProxmoxSyncService::class)->run($c, $sync)['code'])->toBe('disabled');
    Http::assertNothingSent();
})->with([true, false]);

test('proxmox connection test records fact without inventory changes', function () {
    $c = pveConnection();
    pveFake();
    expect(app(ProxmoxSyncService::class)->run($c, false)['status'])->toBe('online');
    expect($c->fresh()->version)->toBe('8.4.1')->and($c->fresh()->last_checked_at)->not->toBeNull()
        ->and($c->nodes()->count())->toBe(0)->and($c->fresh()->last_synced_at)->toBeNull();
    Http::assertSentCount(1);
});

test('proxmox rejects fetched snapshot after concurrent settings edit', function () {
    $c = pveConnection();
    Http::fake(function () use ($c) {
        $c->increment('revision');

        return Http::response(['data' => ['version' => '8.4']]);
    });
    expect(app(ProxmoxSyncService::class)->run($c, false)['code'])->toBe('superseded')
        ->and($c->fresh()->status)->toBe('unknown');
});

test('proxmox rechecks location after fetch', function () {
    $c = pveConnection();
    Http::fake(function () use ($c) {
        $c->location->update(['enabled' => false]);

        return Http::response(['data' => ['version' => '8.4']]);
    });
    expect(app(ProxmoxSyncService::class)->run($c, false)['code'])->toBe('location_unavailable')
        ->and($c->fresh()->last_checked_at)->toBeNull();
});

test('proxmox database error rolls back entire inventory', function () {
    $c = pveConnection();
    pveFake();
    app(ProxmoxSyncService::class)->run($c);
    $before = [$c->nodes()->get()->toArray(), $c->guests()->get()->toArray()];
    $rows = pveResources();
    $rows[2]['name'] = 'Changed';
    pveFake($rows);
    ProxmoxGuest::updating(fn () => throw new RuntimeException('synthetic-test-secret'));
    try {
        expect(app(ProxmoxSyncService::class)->run($c)['code'])->toBe('internal');
        expect([$c->nodes()->get()->toArray(), $c->guests()->get()->toArray()])->toBe($before);
    } finally {
        ProxmoxGuest::flushEventListeners();
    }
});

test('proxmox classifies API failures and preserves their safe cause', function (string $failure, string $status, string $code, string $message) {
    $c = pveConnection();
    pveFake();
    app(ProxmoxSyncService::class)->run($c);
    $before = [$c->nodes()->get()->toArray(), $c->guests()->get()->toArray()];
    Http::swap(new \Illuminate\Http\Client\Factory);
    Http::preventStrayRequests();
    Http::fake(function () use ($failure) {
        if ($failure === 'network') {
            throw new \Illuminate\Http\Client\ConnectionException('synthetic-test-secret');
        }
        if ($failure === 'internal') {
            throw new RuntimeException('synthetic-test-secret');
        }
        return $failure === 'payload' ? Http::response('{') : Http::response('synthetic-test-secret', (int) $failure);
    });
    $result = app(ProxmoxSyncService::class)->run($c);
    expect($result['status'])->toBe($status)->and($result['code'])->toBe($code)
        ->and($c->fresh()->status)->toBe($status)->and($c->fresh()->last_error_code)->toBe($code)
        ->and(\App\Services\ProxmoxApiException::messageFor($code))->toBe($message)
        ->and([$c->nodes()->get()->toArray(), $c->guests()->get()->toArray()])->toBe($before);
})->with([
    ['401', 'unknown', 'auth', 'Ошибка авторизации API'],
    ['403', 'unknown', 'auth', 'Ошибка авторизации API'],
    ['payload', 'unknown', 'payload', 'Некорректный ответ API'],
    ['network', 'offline', 'network', 'Ошибка подключения'],
    ['500', 'offline', 'http', 'Ошибка HTTP API'],
    ['internal', 'unknown', 'internal', 'Внутренняя ошибка'],
]);
