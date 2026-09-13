<?php

use App\Models\{Event, MonitorCheck, User, Vps, Website};
use App\Services\{LocalDeviceHealthCheckService, LocalDeviceMonitoringService, MonitorCheckRecorder, MonitorCheckStatisticsService, VpsHealthCheckService, VpsMonitoringService, WebsiteHealthCheckService, WebsiteMonitoringService};
use Illuminate\Support\Facades\{DB, Exceptions, Http, Schema};

require_once __DIR__.'/../Support/LocalInfrastructure.php';

beforeEach(function () {
    $this->travelTo(\Carbon\Carbon::parse('2026-09-11 12:00:00'));
    config(['services.max.bot_token' => '', 'services.max.user_id' => '', 'services.max.api_url' => 'https://max.invalid']);
    Http::preventStrayRequests();
});

function historyMonitor(string $type, array $attributes = [])
{
    return match ($type) {
        'vps' => Vps::create($attributes + ['name' => 'History VPS', 'ip_address' => '192.0.2.1', 'check_port' => 22, 'enabled' => true]),
        'website' => Website::create($attributes + ['name' => 'History website', 'url' => 'https://example.invalid', 'enabled' => true]),
        'local_device' => localDevice($attributes),
    };
}

function historyChecker(string $type): string
{
    return match ($type) {
        'vps' => VpsHealthCheckService::class, 'website' => WebsiteHealthCheckService::class,
        'local_device' => LocalDeviceHealthCheckService::class,
    };
}

function historyService(string $type)
{
    return app(match ($type) {
        'vps' => VpsMonitoringService::class, 'website' => WebsiteMonitoringService::class,
        'local_device' => LocalDeviceMonitoringService::class,
    });
}

function historyFake(string $type, string $status, int $times = 1): void
{
    test()->mock(historyChecker($type))->shouldReceive('check')->times($times)->andReturnUsing(function ($model) use ($type, $status) {
        if ($status === 'unknown') throw new LogicException('Synthetic checker bug');
        $result = ['status' => $status, 'response_ms' => $status === 'online' ? 7 : null];
        $update = ['status' => $status, 'last_checked_at' => now(), 'last_response_ms' => $result['response_ms']];
        if ($type === 'website') {
            $result['http_status'] = $status === 'online' ? 200 : 503;
            $update['last_http_status'] = $result['http_status'];
        }
        $model->update($update);
        return $result;
    });
}

function historyTrigger(string $type, string $origin, int $id): void
{
    $path = match ($type) { 'vps' => 'vps', 'website' => 'websites', 'local_device' => 'local-devices' };
    if ($origin === 'scheduled') {
        test()->artisan('monitor:'.$path)->run();
    } else {
        test()->actingAs(User::factory()->create())->post('/'.$path.($origin === 'manual' ? "/{$id}/check" : '/check-all'));
    }
}

test('every entry point records exactly one factual sample per attempt', function (string $type, string $origin, string $status) {
    $model = historyMonitor($type);
    historyFake($type, $status);
    historyTrigger($type, $origin, $model->id);
    $row = MonitorCheck::sole();
    expect($row->monitor_type)->toBe($type)->and($row->monitor_id)->toBe($model->id)
        ->and($row->origin)->toBe($origin)->and($row->status)->toBe($status)
        ->and($row->checked_at->equalTo(now()))->toBeTrue()
        ->and($row->response_ms)->toBe($status === 'online' ? 7 : null)
        ->and($row->http_status)->toBe($type === 'website' && $status !== 'unknown' ? ($status === 'online' ? 200 : 503) : null);
    if ($status === 'unknown') expect(Event::count())->toBe(0);
})->with(['vps', 'website', 'local_device'])->with(['scheduled', 'manual', 'manual_batch'])->with(['online', 'offline', 'unknown']);

test('batch records one row for each checked object and skips disabled monitors', function (string $type, string $origin) {
    $a = historyMonitor($type); $b = historyMonitor($type); $disabled = historyMonitor($type, ['enabled' => false]);
    historyFake($type, 'online', 2);
    historyTrigger($type, $origin, $a->id);
    expect(MonitorCheck::count())->toBe(2)->and(MonitorCheck::pluck('monitor_id')->all())->toBe([$a->id, $b->id])
        ->and($disabled->fresh()->last_checked_at)->toBeNull();
})->with(['vps', 'website', 'local_device'])->with(['scheduled', 'manual_batch']);

test('paused location creates no scheduled sample but manual diagnostic is excluded from uptime', function () {
    $device = localDevice(['location_id' => localLocation(['enabled' => false])->id]);
    historyFake('local_device', 'online');
    historyTrigger('local_device', 'scheduled', $device->id);
    expect(MonitorCheck::count())->toBe(0);
    historyTrigger('local_device', 'manual', $device->id);
    expect(MonitorCheck::sole()->origin)->toBe('manual')->and(Event::count())->toBe(0)
        ->and(app(MonitorCheckStatisticsService::class)->forMonitors('local_device', [$device->id])[$device->id]['30d']['uptime_percent'])->toBeNull();
});

test('recorder exception or failed SQL insert cannot change health or incident processing', function (string $type, string $failure) {
    Exceptions::fake();
    $model = historyMonitor($type, ['status' => 'offline']);
    if ($type !== 'vps') $model->update(['failure_started_at' => now()->subHour(), 'incident_confirmed_at' => now()->subMinutes(30)]);
    historyFake($type, 'online');
    $this->partialMock(MonitorCheckRecorder::class)->shouldReceive('record')->once()->andReturnUsing(function () use ($failure) {
        if ($failure === 'exception') throw new RuntimeException('Synthetic recorder failure');
        DB::table('monitor_checks')->insert(['not_a_column' => 1]);
    });
    $level = DB::transactionLevel();
    $result = historyService($type)->monitor($model, origin: 'scheduled');
    expect($result['status'])->toBe('online')->and($result['event_created'])->toBeTrue()
        ->and($model->fresh()->status)->toBe('online')->and(Event::count())->toBe(1)
        ->and(MonitorCheck::count())->toBe(0)->and(DB::transactionLevel())->toBe($level);
    Exceptions::assertReportedCount(1);
    Http::assertNothingSent();
})->with(['vps', 'website', 'local_device'])->with(['exception', 'sql']);

test('checker exception propagates unchanged even when recording also fails', function (string $type) {
    Exceptions::fake();
    $model = historyMonitor($type);
    historyFake($type, 'unknown');
    $this->partialMock(MonitorCheckRecorder::class)->shouldReceive('record')->once()->andThrow(new RuntimeException('Recorder failure'));
    expect(fn () => historyService($type)->monitor($model, origin: 'scheduled'))->toThrow(LogicException::class, 'Synthetic checker bug');
    Exceptions::assertReportedCount(1);
    expect(Event::count())->toBe(0);
})->with(['vps', 'website', 'local_device']);

test('real Website checker preserves HTTP semantics in history', function (?int $code, string $status) {
    Http::fake(fn () => $code === null ? throw new \Illuminate\Http\Client\ConnectionException('Synthetic transport failure') : Http::response('', $code));
    $site = historyMonitor('website');
    $result = historyService('website')->monitor($site, origin: 'manual');
    $row = MonitorCheck::sole();
    expect($row->status)->toBe($status)->and($row->http_status)->toBe($code)
        ->and($row->response_ms)->toBe($result['response_ms'])->toBeInt()->toBeGreaterThanOrEqual(0);
})->with([[200, 'online'], [302, 'online'], [404, 'offline'], [503, 'offline'], [null, 'offline']]);

test('administrative edits pause reset delete and page reads do not create samples', function () {
    $this->actingAs(User::factory()->create());
    $device = localDevice();
    $this->put('/local-devices/'.$device->id, localPayload($device, ['host' => '192.0.2.20', 'enabled' => false]))->assertRedirect();
    $location = $device->location;
    $this->put('/locations/'.$location->id, ['name' => $location->name, 'connection_type' => 'local', 'enabled' => false])->assertRedirect();
    $this->get('/local-infrastructure')->assertOk();
    $this->delete('/local-devices/'.$device->id)->assertRedirect();
    expect(MonitorCheck::count())->toBe(0);
});

test('schema has expected fields casts indexes and no polymorphic foreign key', function () {
    expect(Schema::hasColumns('monitor_checks', ['id', 'monitor_type', 'monitor_id', 'origin', 'status', 'checked_at', 'response_ms', 'http_status']))->toBeTrue();
    $indexes = collect(Schema::getIndexes('monitor_checks'));
    expect($indexes->firstWhere('name', 'monitor_checks_statistics_index')['columns'])->toBe(['monitor_type', 'monitor_id', 'origin', 'checked_at'])
        ->and($indexes->firstWhere('name', 'monitor_checks_checked_at_index')['columns'])->toBe(['checked_at'])
        ->and(Schema::getForeignKeys('monitor_checks'))->toBe([])
        ->and(MonitorCheck::ORIGINS)->toBe(['scheduled', 'manual', 'manual_batch'])
        ->and((new MonitorCheck)->timestamps)->toBeFalse();
});

test('invalid sample values are rejected', function (string $field) {
    $args = ['vps', 1, 'scheduled', ['status' => 'online', 'checked_at' => now(), 'response_ms' => 1, 'http_status' => null]];
    if ($field === 'status') $args[3]['status'] = 'invalid';
    else $args[$field === 'type' ? 0 : 2] = 'invalid';
    expect(fn () => app(MonitorCheckRecorder::class)->record(...$args))->toThrow(InvalidArgumentException::class);
    expect(MonitorCheck::count())->toBe(0);
})->with(['type', 'origin', 'status']);
