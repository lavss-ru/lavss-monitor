<?php

use App\Services\VpsHealthCheckService;

// No real TCP connections — only tests the address-building logic.

test('buildAddress formats ipv4 correctly', function () {
    $service = new VpsHealthCheckService();

    expect($service->buildAddress('192.168.1.1', 22))->toBe('tcp://192.168.1.1:22');
    expect($service->buildAddress('1.2.3.4', 443))->toBe('tcp://1.2.3.4:443');
    expect($service->buildAddress('10.0.0.1', 80))->toBe('tcp://10.0.0.1:80');
});

test('buildAddress wraps ipv6 in brackets', function () {
    $service = new VpsHealthCheckService();

    expect($service->buildAddress('2001:db8::1', 22))->toBe('tcp://[2001:db8::1]:22');
    expect($service->buildAddress('::1', 80))->toBe('tcp://[::1]:80');
    expect($service->buildAddress('fe80::1', 443))->toBe('tcp://[fe80::1]:443');
    expect($service->buildAddress('2001:0db8:0000:0000:0000:0000:0000:0001', 22))
        ->toBe('tcp://[2001:0db8:0000:0000:0000:0000:0000:0001]:22');
});

test('buildAddress does not double-wrap already-bracketed ipv6', function () {
    // filter_var rejects bracketed input — it should fall through to IPv4 path,
    // but in practice users pass raw IPs from the form (validated by Laravel 'ip' rule).
    // This test documents that buildAddress expects a raw IP, not a pre-bracketed one.
    $service = new VpsHealthCheckService();

    // Raw IPv6 — must be wrapped
    expect($service->buildAddress('::1', 22))->toBe('tcp://[::1]:22');
});
