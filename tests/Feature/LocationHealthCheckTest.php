<?php

use App\Services\LocationHealthCheckService;
use App\Services\VpsHealthCheckService;

require_once __DIR__.'/../Support/LocationConnectivity.php';

test('real checker updates factual health without network via transport seam', function (string $host, string $address, bool $online) {
    $device = probeLocation(['probe_host' => $host, 'probe_port' => 22]);
    $socket = $online ? fopen('php://memory', 'r+') : false;
    $checker = Mockery::mock(LocationHealthCheckService::class, [new VpsHealthCheckService])->makePartial()->shouldAllowMockingProtectedMethods();
    $checker->shouldReceive('connect')->once()->with($address)->andReturn($socket);
    $result = $checker->check($device);
    expect($result['status'])->toBe($online ? 'online' : 'offline')
        ->and($device->fresh()->last_checked_at)->not->toBeNull()
        ->and($device->fresh()->status)->toBe($result['status']);
    if ($online) {
        expect($result['response_ms'])->toBeInt()->toBeGreaterThanOrEqual(0)->and(is_resource($socket))->toBeFalse();
    } else {
        expect($result['response_ms'])->toBeNull();
    }
    expect(LocationHealthCheckService::TIMEOUT)->toBe(3.0);
})->with([['192.168.1.10', 'tcp://192.168.1.10:22'], ['pve.internal', 'tcp://pve.internal:22'], ['fd00::1', 'tcp://[fd00::1]:22']])->with([true, false]);

test('checker rejects unsafe endpoints before transport even if database validation was bypassed', function () {
    $device = probeLocation(['probe_host' => 'tcp://bad/path']);
    $checker = Mockery::mock(LocationHealthCheckService::class, [new VpsHealthCheckService])->makePartial()->shouldAllowMockingProtectedMethods();
    $checker->shouldNotReceive('connect');
    expect(fn () => $checker->check($device))->toThrow(InvalidArgumentException::class);
});

test('TCP implementation contains no shell execution API', function () {
    $source = file_get_contents(app_path('Services/LocationHealthCheckService.php'));
    foreach (token_get_all($source) as $token) {
        if (is_array($token) && $token[0] === T_STRING) {
            expect(strtolower($token[1]))->not->toBeIn(['shell_exec', 'exec', 'system', 'passthru', 'popen', 'proc_open']);
        }
        if (is_string($token)) {
            expect($token)->not->toBe('`');
        }
    }
    expect($source)->toContain('stream_socket_client', 'STREAM_CLIENT_CONNECT');
});
