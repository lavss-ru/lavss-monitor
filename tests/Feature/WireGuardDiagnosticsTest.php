<?php

use App\Models\Event;
use App\Models\MonitorCheck;
use App\Services\LocalDeviceMonitoringService;
use App\Services\WireGuardDiagnosticsService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

require_once __DIR__.'/../Support/LocationConnectivity.php';

beforeEach(function () {
    $this->travelTo(now()->startOfSecond());
    Process::preventStrayProcesses();
});

test('safe selective wg inspection reports handshake and transfer independently from connectivity', function (int $age, string $state) {
    $key = base64_encode(str_repeat('a', 32));
    $location = probeLocation(['wireguard_interface' => 'wg-test', 'status' => 'online']);
    Process::fake(fn ($pending) => Process::result(output: str_ends_with(implode(' ', $pending->command), 'latest-handshakes')
        ? $key."\t".($age < 0 ? 0 : now()->timestamp - $age)."\n" : $key."\t123\t456\n"));
    $result = app(WireGuardDiagnosticsService::class)->inspect($location);
    expect($result['wireguard_diagnostic_state'])->toBe($state)->and($result['wireguard_rx_bytes'])->toBe(123)
        ->and($result['wireguard_tx_bytes'])->toBe(456)->and($location->fresh()->status)->toBe('online');
    Process::assertRan(fn ($process) => $process->command === ['wg', 'show', 'wg-test', 'latest-handshakes'] && $process->timeout === 2);
    Process::assertRan(fn ($process) => $process->command === ['wg', 'show', 'wg-test', 'transfer']);
    Process::assertRanTimes(fn () => true, 2);
})->with([[18, 'fresh'], [600, 'stale'], [-1, 'never']]);

test('missing interface binary permissions and malformed output are unknown', function (string $output, int $exit) {
    Process::fake(fn () => Process::result(output: $output, errorOutput: 'synthetic failure', exitCode: $exit));
    $result = app(WireGuardDiagnosticsService::class)->inspect(probeLocation(['wireguard_interface' => 'wg-test']));
    expect($result['wireguard_diagnostic_state'])->toBe('unknown')->and($result['wireguard_last_handshake_at'])->toBeNull()
        ->and($result['wireguard_rx_bytes'])->toBeNull();
})->with([['', 1], ['', 127], ['malformed', 0], ['', 0], [base64_encode(str_repeat('a', 32))."\tNaN", 0]]);

test('multiple peers require explicit selection and never guess peer', function (bool $selected, bool $missing) {
    $a = base64_encode(str_repeat('a', 32));
    $b = base64_encode(str_repeat('b', 32));
    $location = probeLocation(['wireguard_interface' => 'wg-test', 'wireguard_peer_public_key' => $selected ? ($missing ? base64_encode(str_repeat('c', 32)) : $b) : null]);
    Process::fake(fn ($pending) => Process::result(output: str_ends_with(implode(' ', $pending->command), 'latest-handshakes')
        ? "$a\t0\n$b\t".now()->timestamp."\n" : "$a\t1\t2\n$b\t3\t4\n"));
    $result = app(WireGuardDiagnosticsService::class)->inspect($location);
    expect($result['wireguard_diagnostic_state'])->toBe($selected && ! $missing ? 'fresh' : 'unknown')
        ->and($result['wireguard_rx_bytes'])->toBe($selected && ! $missing ? 3 : null);
})->with([[false, false], [true, false], [true, true]]);

test('invalid interface never reaches process API', function (string $name) {
    Process::fake();
    expect(app(WireGuardDiagnosticsService::class)->inspect(probeLocation(['wireguard_interface' => $name]))['wireguard_diagnostic_state'])->toBe('unknown');
    Process::assertNothingRan();
})->with(['wg;id', '-x', '../wg', 'wg$(id)', 'wg name']);

test('wg failure leaves successful location TCP status and history intact', function () {
    Process::fake(fn () => Process::result(exitCode: 127));
    locationFakeCheck('online');
    $location = probeLocation(['wireguard_interface' => 'wg-test']);
    locationMonitor($location);
    expect($location->fresh()->status)->toBe('online')->and($location->fresh()->wireguard_diagnostic_state)->toBe('unknown')
        ->and(MonitorCheck::sole()->status)->toBe('online');
});

test('unavailable WG metadata never drives incidents notifications or child suppression', function () {
    config(['services.max.bot_token' => 'fake-token', 'services.max.user_id' => '123', 'services.max.api_url' => 'https://max.invalid']);
    localHttpFake(['https://max.invalid/*' => Http::response(['success' => true])]);
    Process::fake(fn () => Process::result(exitCode: 127));
    $location = probeLocation(['wireguard_interface' => 'wg-test']);
    $device = localDevice(['location_id' => $location->id]);
    locationFakeCheck('online');
    localFakeCheck('online');
    $cycle = fn () => app(LocalDeviceMonitoringService::class)->checkAll('scheduled');
    $cycle();
    $this->travel(120)->seconds();
    $cycle();
    expect($location->fresh()->wireguard_diagnostic_state)->toBe('unknown')
        ->and($location->fresh()->status)->toBe('online')->and($device->fresh()->status)->toBe('online')
        ->and($location->fresh()->blocksChildren())->toBeFalse()->and(Event::count())->toBe(0);
    Http::assertNothingSent();
    locationFakeCheck('offline');
    $cycle();
    $this->travel(120)->seconds();
    $cycle();
    expect($location->fresh()->status)->toBe('offline')->and($device->fresh()->status)->toBe('unknown')
        ->and(Event::sole()->type)->toBe('location');
    Http::assertSentCount(1);
    locationFakeCheck('online');
    $cycle();
    expect($location->fresh()->wireguard_diagnostic_state)->toBe('unknown')
        ->and($location->fresh()->status)->toBe('online')->and($device->fresh()->status)->toBe('online')
        ->and(Event::where('type', 'local_device')->count())->toBe(0)
        ->and(Event::count())->toBe(2);
    Http::assertSentCount(2);
});
