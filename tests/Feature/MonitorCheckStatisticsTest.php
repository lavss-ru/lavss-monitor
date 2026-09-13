<?php

use App\Models\{MonitorCheck, User, Vps, Website};
use App\Services\MonitorCheckStatisticsService;
use Illuminate\Support\Facades\{DB, Http};
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Support/LocalInfrastructure.php';

beforeEach(function () {
    $this->travelTo(\Carbon\Carbon::parse('2026-09-11 12:00:00'));
    Http::preventStrayRequests();
});

function statsSample(array $attributes = []): MonitorCheck
{
    return MonitorCheck::create($attributes + ['monitor_type' => 'vps', 'monitor_id' => 1, 'origin' => 'scheduled',
        'status' => 'online', 'checked_at' => now(), 'response_ms' => 10, 'http_status' => null]);
}

test('uptime excludes unknown and manual samples and aggregates every metric in one query', function () {
    for ($i = 0; $i < 8; $i++) statsSample(['response_ms' => $i === 0 ? null : 14]);
    for ($i = 0; $i < 2; $i++) statsSample(['status' => 'offline', 'response_ms' => 3000]);
    statsSample(['status' => 'unknown', 'response_ms' => null]);
    foreach (['manual', 'manual_batch'] as $origin) {
        statsSample(['origin' => $origin, 'response_ms' => 9000]);
        statsSample(['origin' => $origin, 'status' => 'offline']);
    }
    statsSample(['monitor_type' => 'website', 'status' => 'offline']);
    statsSample(['monitor_id' => 2, 'status' => 'offline']);
    statsSample(['checked_at' => now()->addSecond(), 'status' => 'offline']);
    DB::enableQueryLog(); DB::flushQueryLog();
    $stats = app(MonitorCheckStatisticsService::class)->forMonitors('vps', [1, 2, 3]);
    $queries = DB::getQueryLog(); DB::disableQueryLog();
    expect($queries)->toHaveCount(1);
    foreach (['24h', '7d', '30d'] as $period) {
        expect($stats[1][$period])->toBe(['uptime_percent' => 80.0, 'online_count' => 8, 'offline_count' => 2,
            'unknown_count' => 1, 'measured_count' => 10, 'total_count' => 11, 'average_response_ms' => 14.0]);
        expect($stats[2][$period]['uptime_percent'])->toBe(0.0)->and($stats[3][$period]['uptime_percent'])->toBeNull();
    }
});

test('rolling windows include exact cutoff and exclude one second older', function (string $period, int $days) {
    statsSample(['checked_at' => now()->subDays($days), 'response_ms' => 20]);
    statsSample(['checked_at' => now()->subDays($days)->subSecond(), 'status' => 'offline']);
    $stats = app(MonitorCheckStatisticsService::class)->forMonitors('vps', [1])[1][$period];
    expect($stats['online_count'])->toBe(1)->and($stats['offline_count'])->toBe(0)
        ->and($stats['uptime_percent'])->toBe(100.0)->and($stats['average_response_ms'])->toBe(20.0);
})->with([['24h', 1], ['7d', 7], ['30d', 30]]);

test('no measured data returns null and unknown is counted separately', function () {
    statsSample(['status' => 'unknown', 'response_ms' => null]);
    statsSample(['origin' => 'manual']);
    $stats = app(MonitorCheckStatisticsService::class)->forMonitors('vps', [1, 2]);
    expect($stats[1]['30d']['uptime_percent'])->toBeNull()->and($stats[1]['30d']['unknown_count'])->toBe(1)
        ->and($stats[1]['30d']['average_response_ms'])->toBeNull()
        ->and($stats[2]['30d']['total_count'])->toBe(0);
    DB::enableQueryLog(); DB::flushQueryLog();
    expect(app(MonitorCheckStatisticsService::class)->forMonitors('vps', []))->toBe([])
        ->and(DB::getQueryLog())->toBe([]);
    DB::disableQueryLog();
});

test('average includes online zero latency excludes null and rounds numeric outputs', function () {
    statsSample(['response_ms' => 0]);
    statsSample(['response_ms' => 5]);
    statsSample(['response_ms' => null]);
    statsSample(['status' => 'offline', 'response_ms' => 3000]);
    statsSample(['status' => 'offline', 'response_ms' => 3000]);
    statsSample(['status' => 'offline', 'response_ms' => 3000]);
    statsSample(['status' => 'offline', 'response_ms' => 3000]);
    $stats = app(MonitorCheckStatisticsService::class)->forMonitors('vps', [1])[1]['24h'];
    expect($stats['uptime_percent'])->toBe(42.86)->and($stats['average_response_ms'])->toBe(2.5);
});

test('each list page requests one aggregation and only exposes existing monitors', function (string $type, string $path, string $prop) {
    $monitor = match ($type) {
        'vps' => Vps::create(['name' => 'VPS', 'ip_address' => '192.0.2.1']),
        'website' => Website::create(['name' => 'Site', 'url' => 'https://example.invalid']),
        'local_device' => localDevice(),
    };
    statsSample(['monitor_type' => $type, 'monitor_id' => $monitor->id]);
    statsSample(['monitor_type' => $type, 'monitor_id' => 9999]);
    $this->actingAs(User::factory()->create());
    DB::enableQueryLog(); DB::flushQueryLog();
    $this->get($path)->assertOk()->assertInertia(fn (Assert $page) => $page->has($prop, 1)
        ->where($prop.'.'.$monitor->id.'.24h.uptime_percent', 100));
    $aggregations = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'SUM(CASE'));
    DB::disableQueryLog();
    expect($aggregations)->toHaveCount(1);
})->with([['vps', '/vps', 'vpsStats'], ['website', '/websites', 'websiteStats'], ['local_device', '/local-infrastructure', 'localDeviceStats']]);

test('locations-only polling never invokes statistics or scans monitor_checks', function () {
    localDevice();
    $this->mock(MonitorCheckStatisticsService::class)->shouldNotReceive('forMonitors');
    $this->actingAs(User::factory()->create());
    DB::enableQueryLog(); DB::flushQueryLog();
    $this->get('/local-infrastructure', ['X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'LocalInfrastructure/Index',
        'X-Inertia-Partial-Data' => 'locations'])->assertOk()->assertJsonMissingPath('props.localDeviceStats')->assertJsonCount(1, 'props.locations');
    expect(collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'monitor_checks')))->toHaveCount(0);
    DB::disableQueryLog();
});

test('deleted monitor history remains but is not exposed by list page', function () {
    $vps = Vps::create(['name' => 'Deleted', 'ip_address' => '192.0.2.1']);
    statsSample(['monitor_id' => $vps->id]);
    $vps->delete();
    $this->actingAs(User::factory()->create())->get('/vps')->assertInertia(fn (Assert $page) => $page->has('vpsStats', 0));
    expect(MonitorCheck::count())->toBe(1);
});
