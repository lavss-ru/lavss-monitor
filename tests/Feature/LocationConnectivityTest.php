<?php

use App\Models\Event;
use App\Models\Location;
use App\Models\MonitorCheck;
use App\Models\User;
use App\Services\LocalDeviceHealthCheckService;
use App\Services\LocalDeviceMonitoringService;
use App\Services\LocationHealthCheckService;
use App\Services\MonitorCheckStatisticsService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/../Support/LocationConnectivity.php';

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-09-14 12:00:00', 'UTC'));
    config(['services.max.bot_token' => 'fake-token', 'services.max.user_id' => '123', 'services.max.api_url' => 'https://max.invalid']);
    localHttpFake(['https://max.invalid/*' => Http::response(['success' => true])]);
    locationFakeCheck('offline');
    localFakeCheck('offline');
});

test('batch checks locations first and avoids cascade even when child delay is zero', function (string $origin) {
    DB::table('notification_rules')->where('monitor_type', 'local_device')->update(['confirmation_seconds' => 0]);
    $location = probeLocation();
    $devices = collect(range(1, 3))->map(fn () => localDevice(['location_id' => $location->id, 'status' => 'online']));
    $service = app(LocalDeviceMonitoringService::class);
    expect($service->checkAll($origin)['checked'])->toBe(4);
    expect(Event::count())->toBe(0);
    foreach ($devices as $device) {
        expect($device->fresh()->failure_started_at)->toBeNull();
    }
    $this->travel(120)->seconds();
    $this->mock(LocalDeviceHealthCheckService::class)->shouldNotReceive('check');
    expect(app(LocalDeviceMonitoringService::class)->checkAll($origin)['checked'])->toBe(1);
    expect(Event::sole()->type)->toBe('location')->and(MonitorCheck::where('monitor_type', 'location')->count())->toBe(2)
        ->and(MonitorCheck::where('monitor_type', 'local_device')->count())->toBe(3);
    foreach ($devices as $device) {
        expect($device->fresh()->status)->toBe('unknown')->and($device->fresh()->incident_confirmed_at)->toBeNull();
    }
    Http::assertSentCount(1);
    app(LocalDeviceMonitoringService::class)->checkAll($origin);
    expect(Event::count())->toBe(1);
    Http::assertSentCount(1);
    locationFakeCheck('online');
    localFakeCheck('online');
    expect(app(LocalDeviceMonitoringService::class)->checkAll($origin)['checked'])->toBe(4);
    foreach ($devices as $device) {
        expect($device->fresh()->status)->toBe('online');
    }
    expect(Event::count())->toBe(2);
    Http::assertSentCount(2);
})->with(['scheduled', 'manual_batch']);

test('existing child incident is frozen during outage and resumes from factual post recovery result', function (string $after) {
    $location = probeLocation(['status' => 'online']);
    $device = localDevice(['location_id' => $location->id]);
    localMonitor($device);
    $this->travel(120)->seconds();
    localMonitor($device);
    $original = $device->fresh()->only(['failure_started_at', 'incident_confirmed_at', 'incident_notified_at']);
    $warning = Event::sole();
    locationMonitor($location);
    $this->travel(120)->seconds();
    locationMonitor($location);
    localFakeCheck('online'); // Must never cause a recovery through an unreachable parent.
    expect(localMonitor($device)['skipped'])->toBeTrue();
    expect($device->fresh()->only(array_keys($original)))->toEqual($original)
        ->and($warning->fresh()->resolved_at)->toBeNull()->and(Event::count())->toBe(2);
    Http::assertSentCount(2);
    locationFakeCheck('online');
    locationMonitor($location);
    localFakeCheck($after);
    localMonitor($device);
    expect(Event::count())->toBe($after === 'online' ? 4 : 3);
    if ($after === 'offline') {
        expect($device->fresh()->only(array_keys($original)))->toEqual($original);
    } else {
        expect($warning->fresh()->resolved_at)->not->toBeNull();
    }
    Http::assertSentCount($after === 'online' ? 4 : 3);
})->with(['online', 'offline']);

test('unknown location is conservative and internal errors cannot confirm failure', function (string $unknown) {
    $location = probeLocation();
    $device = localDevice(['location_id' => $location->id, 'failure_started_at' => now()->subHour()]);
    locationMonitor($location);
    $this->travel(120)->seconds();
    locationFakeCheck($unknown);
    $this->mock(LocalDeviceHealthCheckService::class)->shouldNotReceive('check');
    $result = app(LocalDeviceMonitoringService::class)->checkAll('scheduled');
    expect($result['errors'])->toBe($unknown === 'error' ? 1 : 0)->and($location->fresh()->status)->toBe('unknown')
        ->and($location->fresh()->failure_started_at)->toBeNull()->and($location->fresh()->incident_confirmed_at)->toBeNull()
        ->and($device->fresh()->status)->toBe('unknown')->and($device->fresh()->failure_started_at)->toBeNull()
        ->and(Event::count())->toBe(0)->and(MonitorCheck::where('monitor_type', 'local_device')->count())->toBe(0);
    locationFakeCheck('offline');
    locationMonitor($location);
    expect($location->fresh()->incident_confirmed_at)->toBeNull();
    Http::assertNothingSent();
})->with(['unknown', 'error']);

test('unknown interval preserves a confirmed location incident and suppresses delivery until measured again', function () {
    locationMonitor($location = probeLocation());
    $this->travel(120)->seconds();
    locationMonitor($location);
    $confirmed = $location->fresh()->incident_confirmed_at;
    locationFakeCheck('error');
    expect(fn () => locationMonitor($location))->toThrow(RuntimeException::class);
    expect($location->fresh()->incident_confirmed_at)->toEqual($confirmed)->and(Event::count())->toBe(1);
    Http::assertSentCount(1);
    locationFakeCheck('online');
    locationMonitor($location);
    expect(Event::count())->toBe(2);
    Http::assertSentCount(2);
});

test('transient parent grace freezes existing child transitions and creates no cascade', function () {
    $location = probeLocation(['status' => 'online']);
    $device = localDevice(['location_id' => $location->id]);
    localMonitor($device);
    $this->travel(120)->seconds();
    localMonitor($device);
    locationMonitor($location);
    localFakeCheck('online');
    localMonitor($device);
    expect($device->fresh()->incident_confirmed_at)->not->toBeNull()->and(Event::count())->toBe(1);
    locationFakeCheck('online');
    app(LocalDeviceMonitoringService::class)->checkAll('scheduled');
    expect(Event::where('type', 'location')->count())->toBe(0)->and(Event::count())->toBe(2);
});

test('new child outage after suppression gets a fresh grace interval', function () {
    $location = probeLocation();
    $device = localDevice(['location_id' => $location->id, 'failure_started_at' => now()->subHour()]);
    locationMonitor($location);
    $this->travel(120)->seconds();
    locationMonitor($location);
    localMonitor($device);
    $this->travel(1)->hours();
    locationFakeCheck('online');
    app(LocalDeviceMonitoringService::class)->checkAll('scheduled');
    expect($device->fresh()->failure_started_at->equalTo(now()))->toBeTrue()->and($device->fresh()->incident_confirmed_at)->toBeNull();
    $this->travel(120)->seconds();
    app(LocalDeviceMonitoringService::class)->checkAll('scheduled');
    expect($device->fresh()->incident_confirmed_at)->not->toBeNull()->and(Event::where('type', 'local_device')->count())->toBe(1);
});

test('unmonitored local location keeps existing child behaviour and records no fake parent rows', function () {
    $location = localLocation(['connection_type' => 'local']);
    $device = localDevice(['location_id' => $location->id]);
    $this->mock(LocationHealthCheckService::class)->shouldNotReceive('check');
    app(LocalDeviceMonitoringService::class)->checkAll('scheduled');
    $this->travel(120)->seconds();
    app(LocalDeviceMonitoringService::class)->checkAll('scheduled');
    expect($device->fresh()->incident_confirmed_at)->not->toBeNull()->and($location->fresh()->status)->toBe('unknown')
        ->and(MonitorCheck::where('monitor_type', 'location')->count())->toBe(0);
});

test('manual location check and infrastructure entry points record exact origin', function (string $origin) {
    $location = probeLocation();
    locationFakeCheck('online');
    if ($origin === 'scheduled') {
        $this->artisan('monitor:local-devices')->assertExitCode(0);
    } else {
        $this->actingAs(User::factory()->create())->post($origin === 'manual' ? "/locations/{$location->id}/check" : '/local-devices/check-all')->assertRedirect();
    }
    expect(MonitorCheck::sole()->origin)->toBe($origin)->and(MonitorCheck::sole()->monitor_type)->toBe('location')
        ->and(MonitorCheck::sole()->response_ms)->toBe(7);
})->with(['scheduled', 'manual', 'manual_batch']);

test('manual child check probes parent first and respects suppression', function () {
    $location = probeLocation(['status' => 'offline', 'failure_started_at' => now()->subHour(), 'incident_confirmed_at' => now()->subHour()]);
    $device = localDevice(['location_id' => $location->id]);
    $this->mock(LocalDeviceHealthCheckService::class)->shouldNotReceive('check');
    $this->actingAs(User::factory()->create())->post("/local-devices/{$device->id}/check")->assertRedirect();
    expect(MonitorCheck::sole()->monitor_type)->toBe('location')->and(MonitorCheck::sole()->origin)->toBe('manual')
        ->and($device->fresh()->status)->toBe('unknown');
});

test('location uptime excludes unknown and manual samples and uses shared retention', function () {
    $location = probeLocation();
    foreach (['online', 'offline', 'unknown'] as $status) {
        locationFakeCheck($status);
        locationMonitor($location);
    }
    locationFakeCheck('offline');
    locationMonitor($location, 'manual');
    locationMonitor($location, 'manual_batch');
    $stats = app(MonitorCheckStatisticsService::class)->forMonitors('location', [$location->id]);
    foreach (['24h', '7d', '30d'] as $window) {
        expect($stats[$location->id][$window]['uptime_percent'])->toBe(50.0)
            ->and($stats[$location->id][$window]['unknown_count'])->toBe(1);
    }
    MonitorCheck::query()->first()->update(['checked_at' => now()->subDays(30)->subSecond()]);
    $this->artisan('monitor:prune-check-history')->assertExitCode(0);
    expect(MonitorCheck::count())->toBe(4);
});

test('location admin changes silently reset incidents and diagnostics', function (string $field, mixed $value) {
    $location = probeLocation();
    locationMonitor($location);
    $this->travel(120)->seconds();
    locationMonitor($location);
    $this->actingAs(User::factory()->create())->put("/locations/{$location->id}", locationPayload($location->fresh(), [$field => $value]))->assertSessionHasNoErrors();
    expect($location->fresh()->status)->toBe('unknown')->and($location->fresh()->incident_confirmed_at)->toBeNull()
        ->and($location->fresh()->incident_notified_at)->toBeNull()->and(Event::sole()->resolved_at)->not->toBeNull();
    Http::assertSentCount(1);
})->with([
    ['probe_host', '2001:db8::1'], ['probe_port', 443], ['wireguard_interface', 'wg-test'],
    ['wireguard_peer_public_key', base64_encode(str_repeat('x', 32))], ['monitoring_enabled', false], ['connection_type', 'vpn'], ['enabled', false],
]);

test('location invalid endpoints metadata and typed inputs are rejected', function (string $field, mixed $value) {
    $location = probeLocation();
    $this->actingAs(User::factory()->create())->put("/locations/{$location->id}", locationPayload($location, [$field => $value]))->assertSessionHasErrors($field);
    expect($location->fresh()->status)->toBe('unknown');
})->with([
    ['probe_host', null], ['probe_host', 'x;id'], ['probe_host', '$(id)'], ['probe_host', 'https://host'], ['probe_host', '999.1.1.1'],
    ['probe_port', null], ['probe_port', 0], ['probe_port', 65536], ['probe_type', 'ping'], ['monitoring_enabled', 'yes'],
    ['wireguard_interface', '../wg'], ['wireguard_interface', 'wg;id'], ['wireguard_interface', '-x'], ['wireguard_interface', str_repeat('x', 16)],
    ['wireguard_peer_public_key', 'not-a-key'],
]);

test('disabled and deleted locations have no probes or deferred retries and renaming preserves incident', function () {
    $location = probeLocation();
    locationMonitor($location);
    $this->travel(120)->seconds();
    locationMonitor($location);
    $this->actingAs(User::factory()->create())->put("/locations/{$location->id}", locationPayload($location->fresh(), ['name' => 'Renamed']))->assertSessionHasNoErrors();
    expect($location->fresh()->incident_confirmed_at)->not->toBeNull();
    $this->put("/locations/{$location->id}", locationPayload($location->fresh(), ['enabled' => false]))->assertSessionHasNoErrors();
    $this->mock(LocationHealthCheckService::class)->shouldNotReceive('check');
    $this->post("/locations/{$location->id}/check")->assertRedirect();
    expect(MonitorCheck::count())->toBe(2);
    $this->delete("/locations/{$location->id}")->assertSessionHasNoErrors();
    app(LocalDeviceMonitoringService::class)->checkAll('scheduled');
    expect(Location::count())->toBe(0)->and(Event::sole()->resolved_at)->not->toBeNull();
    Http::assertSentCount(1);
});

test('dashboard and polling show parent and derived child state without overwriting child incident', function () {
    $location = probeLocation(['status' => 'offline', 'incident_confirmed_at' => now()]);
    $device = localDevice(['location_id' => $location->id, 'status' => 'online']);
    $this->actingAs(User::factory()->create())->get('/dashboard')->assertInertia(fn ($page) => $page
        ->where('dashboard.locations.total', 1)->where('dashboard.locations.offline', 1)
        ->where('dashboard.localDevices.unknown', 1)->where('dashboard.summaries.infrastructure.count', 1));
    $this->get('/local-infrastructure')->assertInertia(fn ($page) => $page->where('locations.0.devices.0.status', 'unknown')
        ->where('locations.0.devices.0.location_unavailable', true)->has('locationStats', 1));
    expect($device->fresh()->status)->toBe('online');
    DB::enableQueryLog();
    DB::flushQueryLog();
    $this->get('/local-infrastructure', ['X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'LocalInfrastructure/Index',
        'X-Inertia-Partial-Data' => 'locations'])->assertOk()->assertJsonPath('props.locations.0.status', 'offline');
    expect(collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'monitor_checks')))->toHaveCount(0);
    DB::disableQueryLog();
});

test('one settings snapshot per infrastructure batch and new rules apply on same service next cycle', function () {
    $location = probeLocation();
    foreach (range(1, 5) as $i) {
        localDevice(['location_id' => $location->id]);
    }
    $service = app(LocalDeviceMonitoringService::class);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $service->checkAll('scheduled');
    $queries = collect(DB::getQueryLog());
    DB::disableQueryLog();
    expect($queries->filter(fn ($q) => str_contains($q['query'], 'select') && str_contains($q['query'], 'notification_settings')))->toHaveCount(1)
        ->and($queries->filter(fn ($q) => str_contains($q['query'], 'select') && str_contains($q['query'], 'notification_rules')))->toHaveCount(1);
    DB::table('notification_rules')->where('monitor_type', 'location')->update(['confirmation_seconds' => 0]);
    $service->checkAll('scheduled');
    expect($location->fresh()->incident_confirmed_at)->not->toBeNull()->and(Event::count())->toBe(1);
});

test('location migrations roll back and restore checks while preserving legacy rows and indexes in sqlite', function () {
    // Roll back dependent type extensions first, as the migrator does.
    $proxmox = require database_path('migrations/2026_09_16_000000_add_proxmox_monitoring.php');
    $proxmox->down();
    $migration = require database_path('migrations/2026_09_14_000001_extend_location_monitor_types.php');
    $schema = require database_path('migrations/2026_09_14_000000_add_location_monitoring_state.php');
    locationFakeCheck('online');
    locationMonitor(probeLocation());
    localMonitor(localDevice());
    $migration->down();
    $schema->down();
    expect(MonitorCheck::count())->toBe(1)->and(MonitorCheck::sole()->monitor_type)->toBe('local_device')
        ->and(Schema::hasColumn('locations', 'status'))->toBeFalse();
    $schema->up();
    $migration->up();
    $proxmox->up();
    locationMonitor(probeLocation());
    expect(MonitorCheck::count())->toBe(2)->and(Schema::hasIndex('monitor_checks', 'monitor_checks_statistics_index'))->toBeTrue()
        ->and(Schema::hasIndex('notification_rules', ['monitor_type'], 'unique'))->toBeTrue();
    expect(fn () => DB::table('notification_rules')->insert(['monitor_type' => 'invalid', 'confirmation_seconds' => 0]))->toThrow(QueryException::class);
    expect(fn () => DB::table('monitor_checks')->insert(['monitor_type' => 'invalid', 'monitor_id' => 1, 'origin' => 'scheduled', 'status' => 'online', 'checked_at' => now()]))->toThrow(QueryException::class);
});

test('new location creation validates probe and local defaults without requiring wireguard fields', function () {
    $this->actingAs(User::factory()->create())->post('/locations', ['name' => 'Home', 'connection_type' => 'local', 'enabled' => true])->assertSessionHasNoErrors();
    $home = Location::sole();
    expect($home->monitoring_enabled)->toBeFalse()->and($home->status)->toBe('unknown')->and($home->wireguard_interface)->toBeNull();
    $this->post('/locations', ['name' => 'Remote', 'connection_type' => 'wireguard', 'enabled' => true, 'monitoring_enabled' => true])->assertSessionHasErrors(['probe_host', 'probe_port']);
    $this->post('/locations', ['name' => 'Remote', 'connection_type' => 'wireguard', 'enabled' => true, 'monitoring_enabled' => true,
        'probe_host' => '2001:db8::1', 'probe_port' => 443])->assertSessionHasNoErrors();
    expect(Location::count())->toBe(2);
});

test('location check requires authentication and array public key returns validation error', function () {
    $location = probeLocation();
    $this->post("/locations/{$location->id}/check")->assertRedirect('/login');
    $this->actingAs(User::factory()->create())->put("/locations/{$location->id}", locationPayload($location, ['wireguard_peer_public_key' => ['invalid']]))->assertSessionHasErrors('wireguard_peer_public_key');
});

test('postgres migration grammar generates both CHECK replacements without connecting to PostgreSQL', function () {
    $original = DB::getDefaultConnection();
    config(['database.connections.stage37_sql_only' => ['driver' => 'pgsql', 'database' => 'unused', 'prefix' => '']]);
    DB::setDefaultConnection('stage37_sql_only');
    $connection = DB::connection();
    // SQL quoting only; this PDO double can never prepare/execute a statement or connect.
    $pdo = Mockery::mock(PDO::class);
    $pdo->shouldReceive('quote')->andReturnUsing(fn ($value) => "'".str_replace("'", "''", $value)."'");
    $pdo->shouldNotReceive('prepare');
    $pdo->shouldNotReceive('exec');
    $connection->setPdo($pdo);
    $connection->setReadPdo($pdo);
    $originalSchema = Schema::getFacadeRoot();
    Schema::swap($connection->getSchemaBuilder());
    try {
        $schema = require database_path('migrations/2026_09_14_000000_add_location_monitoring_state.php');
        $types = require database_path('migrations/2026_09_14_000001_extend_location_monitor_types.php');
        $queries = $connection->pretend(function () use ($schema, $types) {
            $schema->up();
            $types->up();
        });
        $sql = implode("\n", array_column($queries, 'query'));
        expect($sql)->toContain("CHECK (monitor_type IN ('vps', 'website', 'local_device', 'location'))")
            ->toContain('ALTER TABLE monitor_checks DROP CONSTRAINT monitor_checks_monitor_type_check')
            ->toContain('ALTER TABLE notification_rules DROP CONSTRAINT notification_rules_monitor_type_check')
            ->toContain('"monitoring_enabled" boolean not null default');
        $rollback = $connection->pretend(fn () => $types->down());
        expect(implode("\n", array_column($rollback, 'query')))->toContain("CHECK (monitor_type IN ('vps', 'website', 'local_device'))");
    } finally {
        Schema::swap($originalSchema);
        DB::setDefaultConnection($original);
        DB::purge('stage37_sql_only');
    }
});

test('child unconfirmed timer cannot span parent grace with successful child measurements', function () {
    $location = probeLocation(['status' => 'online']);
    $device = localDevice(['location_id' => $location->id]);
    localMonitor($device);
    $this->travel(119)->seconds();
    locationMonitor($location);
    localFakeCheck('online');
    localMonitor($device);
    expect($device->fresh()->failure_started_at)->toBeNull();
    $this->travel(1)->seconds();
    locationFakeCheck('online');
    locationMonitor($location);
    localFakeCheck('offline');
    localMonitor($device);
    expect($device->fresh()->incident_confirmed_at)->toBeNull()->and($device->fresh()->failure_started_at->equalTo(now()))->toBeTrue();
    expect(Event::count())->toBe(0);
    Http::assertNothingSent();
});

test('sqlite monitor type extension retains legacy origin and status CHECK constraints', function (string $field) {
    expect(fn () => DB::table('monitor_checks')->insert(array_replace([
        'monitor_type' => 'location', 'monitor_id' => 1, 'origin' => 'scheduled', 'status' => 'online', 'checked_at' => now(),
    ], [$field => 'invalid'])))->toThrow(QueryException::class);
})->with(['origin', 'status']);
