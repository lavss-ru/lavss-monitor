<?php

use App\Models\Event;
use App\Models\MonitorCheck;
use App\Models\User;
use App\Services\MonitorCheckStatisticsService;
use App\Services\NotificationPolicyService;
use App\Services\ProxmoxMonitoringService;
use App\Services\ProxmoxSummaryService;
use App\Services\ProxmoxSyncService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

require_once __DIR__.'/../Support/Proxmox.php';

function pveMonitoringFake(string $guest = 'running', string $node = 'online'): void
{
    $rows = pveResources();
    $rows[0]['status'] = $node;
    $rows[1]['status'] = 'online';
    $rows[2]['status'] = $guest;
    pveFake($rows);
    Http::fake(['https://max.invalid/*' => Http::response(['success' => true])]);
}

function pveMaxCount(): int
{
    return Http::recorded(fn ($r) => $r->method() === 'POST')->count();
}

beforeEach(function () {
    config(['services.max.bot_token' => 'fake-max-token', 'services.max.user_id' => '123', 'services.max.api_url' => 'https://max.invalid']);
    Http::preventStrayRequests();
});

test('proxmox expected state matrix and safe discovery defaults', function ($expected, $actual, $enabled, $state) {
    $c = pveConnection();
    pveMonitoringFake($actual);
    app(ProxmoxSyncService::class)->run($c);
    $g = $c->guests()->where('vmid', 100)->sole();
    expect($g->monitoring_enabled)->toBeFalse()->and($g->expected_status)->toBe('running');
    $g->update(['monitoring_enabled' => $enabled, 'expected_status' => $expected]);
    app(ProxmoxSyncService::class)->run($c);
    $g->refresh();
    expect($g->failure_started_at !== null)->toBe($state === 'offline');
    $summary = app(ProxmoxSummaryService::class)->summary()['monitoring']['guests'];
    expect(array_sum($summary))->toBe($state === 'ignored' ? 0 : 1);
    if ($state !== 'ignored') {
        expect($summary[$state])->toBe(1);
    }
    expect(Event::count())->toBe(0)->and(pveMaxCount())->toBe(0);
})->with([
    ['running', 'running', true, 'online'], ['running', 'stopped', true, 'offline'], ['running', 'paused', true, 'offline'],
    ['stopped', 'stopped', true, 'online'], ['stopped', 'running', true, 'offline'], ['stopped', 'paused', true, 'offline'],
    ['ignore', 'paused', true, 'ignored'], ['running', 'stopped', false, 'ignored'], ['running', 'unknown', true, 'unknown'],
]);

test('proxmox connection grace confirmation single delivery and factual recovery', function ($code) {
    $c = pveConnection();
    localHttpFake(['*' => function ($r) use ($code) {
        if ($r->method() === 'POST') {
            return Http::response(['success' => true]);
        }
        if ($code === 'network') {
            throw new ConnectionException('synthetic-test-secret');
        }

        return Http::response('', 503);
    }]);
    $sync = app(ProxmoxSyncService::class);
    $sync->run($c, origin: 'scheduled');
    expect($c->fresh()->failure_started_at)->not->toBeNull()->and(Event::count())->toBe(0);
    $this->travel(119)->seconds();
    $sync->run($c);
    expect(Event::count())->toBe(0);
    $this->travel(1)->seconds();
    $sync->run($c);
    $sync->run($c);
    expect(Event::where('type', 'proxmox_connection')->count())->toBe(1)->and(pveMaxCount())->toBe(1);
    expect($c->fresh()->incident_notified_at)->not->toBeNull();
    pveMonitoringFake();
    $sync->run($c);
    $sync->run($c);
    expect(Event::where('type', 'proxmox_connection')->count())->toBe(2)->and(pveMaxCount())->toBe(1)
        ->and($c->fresh()->incident_confirmed_at)->toBeNull()->and(Event::where('severity', 'warning')->whereNull('resolved_at')->count())->toBe(0);
})->with(['network', 'http']);

test('proxmox unknown failures never confirm or falsely recover', function ($code) {
    $c = pveConnection(['failure_started_at' => now()->subMinutes(5), 'incident_confirmed_at' => now()->subMinutes(3), 'incident_notified_at' => now()]);
    localHttpFake(['*' => function () use ($code) {
        if ($code === 'internal') {
            throw new RuntimeException('synthetic-test-secret');
        }

        return $code === 'auth' ? Http::response('synthetic-test-secret', 401) : Http::response('{');
    }]);
    app(ProxmoxSyncService::class)->run($c);
    expect($c->fresh()->status)->toBe('unknown')->and($c->fresh()->last_error_code)->toBe($code)
        ->and($c->fresh()->incident_confirmed_at)->not->toBeNull()->and(Event::count())->toBe(0)->and(pveMaxCount())->toBe(0);
    $c->update(['incident_confirmed_at' => null]);
    app(ProxmoxSyncService::class)->run($c);
    expect($c->fresh()->failure_started_at)->toBeNull();
})->with(['auth', 'payload', 'internal']);

test('proxmox guest confirmed evidence survives parent failure and factual recovery', function (bool $healthy) {
    $c = pveConnection();
    $sync = app(ProxmoxSyncService::class);
    pveMonitoringFake('stopped');
    $sync->run($c);
    $g = $c->guests()->where('vmid', 100)->sole();
    $g->update(['monitoring_enabled' => true]);
    $sync->run($c);
    $this->travel(120)->seconds();
    $sync->run($c);
    $sync->run($c);
    expect(Event::where('type', 'proxmox_guest')->count())->toBe(1)->and(pveMaxCount())->toBe(1);
    Http::assertSent(fn ($r) => $r->method() === 'POST' && str_contains($r['text'], 'VM 100: VM test')
        && str_contains($r['text'], 'pve1') && str_contains($r['text'], 'Ожидается: running; фактически: stopped')
        && ! str_contains($r['text'], 'synthetic-test-secret'));
    $evidence = $g->fresh()->incident_confirmed_at->toISOString();
    localHttpFake(['*' => fn ($r) => $r->method() === 'POST' ? Http::response(['success' => true]) : Http::response('', 503)]);
    $sync->run($c);
    $this->travel(120)->seconds();
    $sync->run($c);
    expect($g->fresh()->incident_confirmed_at->toISOString())->toBe($evidence)
        ->and(Event::where('type', 'proxmox_guest')->count())->toBe(1)->and(pveMaxCount())->toBe(1);
    expect(app(ProxmoxMonitoringService::class)->state($g->fresh(['node']), $c->fresh(['location'])))->toBe('unknown');
    pveMonitoringFake($healthy ? 'running' : 'stopped');
    $sync->run($c);
    $sync->run($c);
    expect(Event::where('type', 'proxmox_guest')->count())->toBe($healthy ? 2 : 1)
        ->and(pveMaxCount())->toBe($healthy ? 2 : 1)->and($g->fresh()->incident_confirmed_at === null)->toBe($healthy);
})->with([true, false]);

test('proxmox node suppression is scoped and unknown never closes incidents', function () {
    DB::table('notification_rules')->where('monitor_type', 'like', 'proxmox_%')->update(['confirmation_seconds' => 0]);
    $c = pveConnection();
    pveMonitoringFake();
    $sync = app(ProxmoxSyncService::class);
    $sync->run($c);
    $c->guests()->update(['monitoring_enabled' => true]);
    pveMonitoringFake('stopped', 'offline');
    $sync->run($c);
    $sync->run($c);
    expect(Event::where('type', 'proxmox_node')->count())->toBe(1)->and(Event::where('type', 'proxmox_guest')->count())->toBe(1)
        ->and($c->guests()->where('vmid', 100)->sole()->incident_confirmed_at)->toBeNull()
        ->and($c->guests()->where('vmid', 101)->sole()->incident_confirmed_at)->not->toBeNull()->and(pveMaxCount())->toBe(2);
    pveMonitoringFake('running', 'unknown');
    $sync->run($c);
    expect(Event::where('type', 'proxmox_node')->count())->toBe(1)->and(pveMaxCount())->toBe(0);
    pveMonitoringFake();
    $sync->run($c);
    $sync->run($c);
    expect(Event::where('type', 'proxmox_node')->count())->toBe(2)->and(pveMaxCount())->toBe(1);
});

test('proxmox location blocked preserves child evidence and produces no samples or alerts', function ($state) {
    $c = pveConnection();
    pveMonitoringFake('stopped');
    $sync = app(ProxmoxSyncService::class);
    $sync->run($c);
    $g = $c->guests()->where('vmid', 100)->sole();
    $g->update(['monitoring_enabled' => true, 'failure_started_at' => now(), 'incident_confirmed_at' => now()]);
    $c->location->update(['monitoring_enabled' => true, 'probe_host' => '192.0.2.1', 'probe_port' => 22,
        'status' => $state, 'incident_confirmed_at' => now()]);
    $count = MonitorCheck::count();
    pveMonitoringFake();
    $sync->run($c);
    $this->travel(300)->seconds();
    $sync->run($c);
    expect(MonitorCheck::count())->toBe($count)->and(Event::count())->toBe(0)->and($g->fresh()->incident_confirmed_at)->not->toBeNull();
    Http::assertNothingSent();
})->with(['offline', 'unknown']);

test('proxmox stale guest is unknown and does not recover until factual reappearance', function () {
    $c = pveConnection();
    pveMonitoringFake();
    $sync = app(ProxmoxSyncService::class);
    $sync->run($c);
    $g = $c->guests()->where('vmid', 100)->sole();
    $g->update(['monitoring_enabled' => true, 'incident_confirmed_at' => now(), 'failure_started_at' => now()]);
    $rows = pveResources();
    unset($rows[2]);
    pveFake(array_values($rows));
    $sync->run($c, origin: 'scheduled');
    expect($g->fresh()->incident_confirmed_at)->not->toBeNull()->and(Event::count())->toBe(0)
        ->and(MonitorCheck::where('monitor_type', 'proxmox_guest')->sole()->status)->toBe('unknown');
    pveMonitoringFake();
    $sync->run($c);
    expect(Event::where('type', 'proxmox_guest')->where('severity', 'info')->count())->toBe(1);
});

test('proxmox rename and node move preserve policy and confirmed state', function () {
    $c = pveConnection();
    pveMonitoringFake();
    $sync = app(ProxmoxSyncService::class);
    $sync->run($c);
    $g = $c->guests()->where('vmid', 100)->sole();
    $g->update(['monitoring_enabled' => true, 'expected_status' => 'stopped', 'incident_confirmed_at' => now(), 'failure_started_at' => now()]);
    $rows = pveResources();
    $rows[1]['status'] = 'online';
    $rows[2]['name'] = 'renamed';
    $rows[2]['node'] = 'pve2';
    pveFake($rows);
    $sync->run($c);
    expect($g->fresh()->name)->toBe('renamed')->and($g->fresh()->node->node_name)->toBe('pve2')
        ->and($g->fresh()->expected_status)->toBe('stopped')->and($g->fresh()->monitoring_enabled)->toBeTrue()
        ->and($g->fresh()->incident_confirmed_at)->not->toBeNull()->and(Event::count())->toBe(0);
});

test('proxmox history origins and availability exclude unknown and manual samples', function ($origin) {
    $c = pveConnection();
    pveMonitoringFake();
    $sync = app(ProxmoxSyncService::class);
    $sync->run($c, origin: $origin);
    $g = $c->guests()->where('vmid', 100)->sole();
    $g->update(['monitoring_enabled' => true]);
    $sync->run($c, origin: $origin);
    pveMonitoringFake('stopped');
    $sync->run($c, origin: $origin);
    pveMonitoringFake('unknown');
    $sync->run($c, origin: $origin);
    expect(MonitorCheck::where('monitor_type', 'proxmox_guest')->pluck('status')->all())->toBe(['online', 'offline', 'unknown'])
        ->and(MonitorCheck::where('origin', '!=', $origin)->count())->toBe(0);
    $stats = app(MonitorCheckStatisticsService::class)->forMonitors('proxmox_guest', [$g->id]);
    expect($stats[$g->id]['24h']['uptime_percent'])->toBe($origin === 'scheduled' ? 50.0 : null);
})->with(['scheduled', 'manual', 'manual_batch']);

test('proxmox notification policy defers delivery without losing confirmation', function ($setting) {
    DB::table('notification_rules')->where('monitor_type', 'proxmox_connection')->update(['confirmation_seconds' => 0]);
    if ($setting === 'down') {
        DB::table('notification_rules')->where('monitor_type', 'proxmox_connection')->update(['down_enabled' => false]);
    } elseif ($setting === 'quiet') {
        DB::table('notification_settings')->update(['quiet_hours_enabled' => true, 'timezone' => 'UTC', 'quiet_hours_start' => '00:00', 'quiet_hours_end' => '23:59']);
    } else {
        DB::table('notification_settings')->update([$setting => false]);
    }
    $this->travelTo(now()->startOfDay()->addHours(12));
    $c = pveConnection();
    localHttpFake(['*' => fn ($r) => $r->method() === 'POST' ? Http::response(['success' => true]) : Http::response('', 503)]);
    $sync = app(ProxmoxSyncService::class);
    $sync->run($c);
    expect($c->fresh()->incident_confirmed_at)->not->toBeNull()->and(pveMaxCount())->toBe(0)->and(Event::count())->toBe(1);
    DB::table('notification_settings')->update(['notifications_enabled' => true, 'max_enabled' => true, 'quiet_hours_enabled' => false]);
    DB::table('notification_rules')->where('monitor_type', 'proxmox_connection')->update(['down_enabled' => true]);
    $sync->run($c);
    $sync->run($c);
    expect(pveMaxCount())->toBe(1)->and(Event::count())->toBe(1);
})->with(['notifications_enabled', 'max_enabled', 'quiet', 'down']);

test('proxmox guest policy editor is scoped validated and invalidates in flight sync', function () {
    $c = pveConnection();
    pveMonitoringFake();
    app(ProxmoxSyncService::class)->run($c);
    $g = $c->guests()->where('vmid', 100)->sole();
    $payload = ['monitoring_target' => 'guest', 'monitor_id' => $g->id, 'monitoring_enabled' => true, 'expected_status' => 'stopped'];
    $this->put('/proxmox/'.$c->id, $payload)->assertRedirect('/login');
    $this->actingAs(User::factory()->create());
    $this->put('/proxmox/'.$c->id, array_replace($payload, ['expected_status' => 'invalid']))->assertSessionHasErrors('expected_status');
    $other = pveConnection();
    $this->put('/proxmox/'.$other->id, $payload)->assertNotFound();
    $revision = $c->fresh()->revision;
    $this->put('/proxmox/'.$c->id, $payload)->assertSessionHasNoErrors();
    expect($g->fresh()->monitoring_enabled)->toBeTrue()->and($g->fresh()->expected_status)->toBe('stopped')
        ->and($c->fresh()->revision)->toBe($revision + 1)->and(Event::count())->toBe(0);
});

test('proxmox manual test evaluates connection only and never old guest snapshot', function () {
    $c = pveConnection();
    pveMonitoringFake('stopped');
    $sync = app(ProxmoxSyncService::class);
    $sync->run($c);
    $c->guests()->update(['monitoring_enabled' => true]);
    $before = MonitorCheck::where('monitor_type', '!=', 'proxmox_connection')->count();
    $this->travel(300)->seconds();
    $sync->run($c, false);
    expect(Event::count())->toBe(0)->and(MonitorCheck::where('monitor_type', '!=', 'proxmox_connection')->count())->toBe($before);
});

test('proxmox version success after outage cannot recover connection or children before full sync', function () {
    $c = pveConnection();
    pveMonitoringFake('stopped');
    $sync = app(ProxmoxSyncService::class);
    $sync->run($c);
    $g = $c->guests()->where('vmid', 100)->sole();
    $g->update(['monitoring_enabled' => true, 'failure_started_at' => now()]);
    localHttpFake(['*' => Http::response('', 503)]);
    $sync->run($c, false);
    expect($g->fresh()->failure_started_at)->toBeNull();
    $this->travel(120)->seconds();
    $sync->run($c, false);
    expect($c->fresh()->incident_confirmed_at)->not->toBeNull();
    pveMonitoringFake();
    $sync->run($c, false);
    expect($c->fresh()->incident_confirmed_at)->not->toBeNull()->and($c->fresh()->last_synced_at)->toBeNull()
        ->and(app(ProxmoxMonitoringService::class)->state($g->fresh(['node']), $c->fresh(['location'])))->toBe('unknown');
    $sync->run($c);
    expect($c->fresh()->incident_confirmed_at)->toBeNull();
});

test('proxmox node grace and stale node never manufacture confirmation or recovery', function () {
    $c = pveConnection();
    pveMonitoringFake('running', 'offline');
    $sync = app(ProxmoxSyncService::class);
    $sync->run($c);
    $node = $c->nodes()->where('node_name', 'pve1')->sole();
    expect($node->failure_started_at)->not->toBeNull()->and(Event::count())->toBe(0);
    $this->travel(119)->seconds();
    $sync->run($c);
    expect(Event::count())->toBe(0);
    $this->travel(1)->seconds();
    $sync->run($c);
    expect($node->fresh()->incident_confirmed_at)->not->toBeNull()->and(pveMaxCount())->toBe(1);
    localHttpFake([
        '*/nodes' => Http::response(['data' => [['node' => 'pve2']]]),
        '*/cluster/resources' => Http::response(['data' => [['type' => 'node', 'node' => 'pve2', 'status' => 'online']]]),
    ]);
    $sync->run($c);
    expect($node->fresh()->stale)->toBeTrue()->and($node->fresh()->incident_confirmed_at)->not->toBeNull()
        ->and(Event::count())->toBe(1)->and(pveMaxCount())->toBe(0);
});

test('proxmox guest confirmation delay is configurable and unknown resets unconfirmed grace', function () {
    DB::table('notification_rules')->where('monitor_type', 'proxmox_guest')->update(['confirmation_seconds' => 30]);
    $c = pveConnection();
    pveMonitoringFake('stopped');
    $sync = app(ProxmoxSyncService::class);
    $sync->run($c);
    $g = $c->guests()->where('vmid', 100)->sole();
    $g->update(['monitoring_enabled' => true]);
    $sync->run($c);
    $this->travel(29)->seconds();
    $sync->run($c);
    expect(Event::count())->toBe(0);
    pveMonitoringFake('unknown');
    $sync->run($c);
    expect($g->fresh()->failure_started_at)->toBeNull();
    pveMonitoringFake('paused');
    $sync->run($c);
    $this->travel(30)->seconds();
    $sync->run($c);
    expect(Event::count())->toBe(1)->and(pveMaxCount())->toBe(1);
});

test('proxmox recovery suppression and absent DOWN delivery cannot cause green flood', function (bool $downEnabled, bool $recoveryEnabled) {
    DB::table('notification_rules')->where('monitor_type', 'proxmox_connection')->update([
        'confirmation_seconds' => 0, 'down_enabled' => $downEnabled, 'recovery_enabled' => $recoveryEnabled]);
    $c = pveConnection();
    localHttpFake(['*' => fn ($r) => $r->method() === 'POST' ? Http::response(['success' => true]) : Http::response('', 503)]);
    $sync = app(ProxmoxSyncService::class);
    $sync->run($c);
    expect(pveMaxCount())->toBe($downEnabled ? 1 : 0);
    pveMonitoringFake();
    $sync->run($c);
    $sync->run($c);
    expect(Event::count())->toBe(2)->and(pveMaxCount())->toBe($downEnabled && $recoveryEnabled ? 1 : 0)
        ->and($c->fresh()->recovery_pending_at)->toBeNull();
})->with([[false, true], [true, false], [true, true]]);

test('proxmox overlapping fetch accepts only one revision and notification', function () {
    DB::table('notification_rules')->where('monitor_type', 'proxmox_connection')->update(['confirmation_seconds' => 0]);
    $c = pveConnection();
    $nested = false;
    $sync = app(ProxmoxSyncService::class);
    localHttpFake(['*' => function ($r) use ($c, $sync, &$nested) {
        if ($r->method() === 'POST') {
            return Http::response(['success' => true]);
        }
        if (! $nested) {
            $nested = true;
            $sync->run($c, false);
        }

        return Http::response('', 503);
    }]);
    expect($sync->run($c, false)['code'])->toBe('superseded')->and(Event::count())->toBe(1)->and(pveMaxCount())->toBe(1)
        ->and(MonitorCheck::count())->toBe(1);
});

test('proxmox delivery rechecks revision and current parent gate', function ($change) {
    $c = pveConnection(['status' => 'offline', 'incident_confirmed_at' => now(), 'failure_started_at' => now()]);
    $revision = $c->revision;
    if ($change === 'revision') {
        $c->increment('revision');
    } else {
        $c->location->update(['enabled' => false]);
    }
    pveMonitoringFake();
    app(ProxmoxMonitoringService::class)->notify($c->id, $revision, true, NotificationPolicyService::load());
    Http::assertNothingSent();
    expect($c->fresh()->incident_notified_at)->toBeNull();
})->with(['revision', 'location']);

test('proxmox disabled monitors do not create events samples or notifications', function () {
    $c = pveConnection(['monitoring_enabled' => false]);
    pveMonitoringFake('stopped', 'offline');
    $sync = app(ProxmoxSyncService::class);
    $sync->run($c);
    $c->nodes()->update(['monitoring_enabled' => false]);
    MonitorCheck::query()->delete();
    $this->travel(300)->seconds();
    $sync->run($c);
    expect(Event::count())->toBe(0)->and(MonitorCheck::count())->toBe(0)->and(pveMaxCount())->toBe(0);
});

test('proxmox failed MAX transport does not leak secrets and retries without another event', function () {
    DB::table('notification_rules')->where('monitor_type', 'proxmox_connection')->update(['confirmation_seconds' => 0]);
    $c = pveConnection();
    Log::spy();
    localHttpFake(['*' => function ($r) {
        if ($r->method() === 'POST') {
            throw new RuntimeException('synthetic-test-secret fake-max-token Authorization');
        }

        return Http::response('', 503);
    }]);
    $sync = app(ProxmoxSyncService::class);
    $sync->run($c);
    expect($c->fresh()->incident_notified_at)->toBeNull()->and(Event::count())->toBe(1);
    Log::shouldHaveReceived('error')->withArgs(fn ($message, $context) => ! str_contains(json_encode([$message, $context]), 'synthetic-test-secret') && ! str_contains(json_encode($context), 'fake-max-token'))->once();
    localHttpFake(['*' => fn ($r) => $r->method() === 'POST' ? Http::response(['success' => true]) : Http::response('', 503)]);
    $sync->run($c);
    $sync->run($c);
    expect(Event::count())->toBe(1)->and(pveMaxCount())->toBe(1);
});

test('proxmox confirmed guest survives node outage then recovers only on factual match', function (bool $healthy) {
    DB::table('notification_rules')->where('monitor_type', 'like', 'proxmox_%')->update(['confirmation_seconds' => 0]);
    $c = pveConnection();
    pveMonitoringFake('stopped');
    $sync = app(ProxmoxSyncService::class);
    $sync->run($c);
    $g = $c->guests()->where('vmid', 100)->sole();
    $g->update(['monitoring_enabled' => true]);
    $sync->run($c);
    expect($g->fresh()->incident_notified_at)->not->toBeNull();
    pveMonitoringFake('running', 'offline');
    $sync->run($c);
    expect($g->fresh()->incident_confirmed_at)->not->toBeNull()->and(pveMaxCount())->toBe(1)
        ->and(Event::where('type', 'proxmox_guest')->where('severity', 'info')->count())->toBe(0);
    pveMonitoringFake($healthy ? 'running' : 'stopped');
    $sync->run($c);
    $sync->run($c);
    expect($g->fresh()->incident_confirmed_at === null)->toBe($healthy)->and(pveMaxCount())->toBe($healthy ? 2 : 1);
})->with([true, false]);

test('proxmox deferred recovery waits through uncertainty and delivers once', function () {
    DB::table('notification_rules')->where('monitor_type', 'proxmox_connection')->update(['confirmation_seconds' => 0]);
    $c = pveConnection();
    localHttpFake(['*' => fn ($r) => $r->method() === 'POST' ? Http::response(['success' => true]) : Http::response('', 503)]);
    $sync = app(ProxmoxSyncService::class);
    $sync->run($c);
    DB::table('notification_settings')->update(['max_enabled' => false]);
    pveMonitoringFake();
    $sync->run($c);
    expect($c->fresh()->recovery_pending_at)->not->toBeNull()->and(pveMaxCount())->toBe(0);
    DB::table('notification_settings')->update(['max_enabled' => true]);
    localHttpFake(['*' => Http::response('', 401)]);
    $sync->run($c);
    expect($c->fresh()->recovery_pending_at)->not->toBeNull()->and(pveMaxCount())->toBe(0);
    pveMonitoringFake();
    $sync->run($c, false);
    expect(pveMaxCount())->toBe(0)->and($c->fresh()->recovery_pending_at)->not->toBeNull();
    $sync->run($c);
    $sync->run($c);
    expect(pveMaxCount())->toBe(1)->and($c->fresh()->recovery_pending_at)->toBeNull()->and(Event::count())->toBe(2);
});

test('proxmox connection failure suppresses every child and dashboard attention without deleting evidence', function () {
    DB::table('notification_rules')->where('monitor_type', 'like', 'proxmox_%')->update(['confirmation_seconds' => 0]);
    $c = pveConnection();
    pveMonitoringFake('stopped');
    $sync = app(ProxmoxSyncService::class);
    $sync->run($c);
    $c->guests()->update(['monitoring_enabled' => true]);
    $sync->run($c);
    expect(Event::where('type', 'proxmox_guest')->count())->toBe(2);
    localHttpFake(['*' => fn ($r) => $r->method() === 'POST' ? Http::response(['success' => true]) : Http::response('', 503)]);
    $sync->run($c);
    $sync->run($c);
    $summary = app(ProxmoxSummaryService::class)->summary();
    expect($summary['monitoring']['nodes'])->toBe(['online' => 0, 'offline' => 0, 'unknown' => 2])
        ->and($summary['monitoring']['guests'])->toBe(['online' => 0, 'offline' => 0, 'unknown' => 2])
        ->and($summary['active_incidents'])->toBe(['proxmox_connection:'.$c->id])->and(pveMaxCount())->toBe(1);
    $this->actingAs(User::factory()->create())->get('/')->assertInertia(fn ($page) => $page->has('dashboard.attentionItems', 1));
    expect(Event::where('type', 'proxmox_guest')->whereNull('resolved_at')->count())->toBe(2);
});

test('proxmox policy edit during fetch supersedes sample and delete creates no recovery', function () {
    $c = pveConnection();
    pveMonitoringFake();
    $sync = app(ProxmoxSyncService::class);
    $sync->run($c);
    $g = $c->guests()->where('vmid', 100)->sole();
    $this->actingAs(User::factory()->create());
    $before = MonitorCheck::count();
    localHttpFake(['*' => function () use ($c, $g) {
        $this->put('/proxmox/'.$c->id, ['monitoring_target' => 'guest', 'monitor_id' => $g->id,
            'monitoring_enabled' => true, 'expected_status' => 'stopped'])->assertSessionHasNoErrors();

        return Http::response(['data' => ['version' => '9.1.4']]);
    }]);
    expect($sync->run($c, false)['code'])->toBe('superseded')->and(MonitorCheck::count())->toBe($before);
    $g->update(['incident_confirmed_at' => now(), 'incident_notified_at' => now()]);
    pveMonitoringFake();
    $this->delete('/proxmox/'.$c->id)->assertRedirect('/proxmox');
    expect(Event::count())->toBe(0);
    Http::assertNothingSent();
});
