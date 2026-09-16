<?php

use App\Services\ProxmoxApiClient;
use App\Services\ProxmoxApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/../Support/Proxmox.php';

beforeEach(function () {
    Http::preventStrayRequests();
});

test('proxmox client sends read only token requests with explicit TLS and timeouts', function (bool $verify) {
    $c = pveConnection(['verify_tls' => $verify, 'host' => '2001:db8::1']);
    Http::fake(function ($request, $options) use ($verify) {
        expect($options['verify'])->toBe($verify)->and($options['timeout'])->toBe(10)
            ->and($options['connect_timeout'])->toBe(3)->and($options['allow_redirects'])->toBeFalse();

        return Http::response(['data' => ['version' => '8.4.1']]);
    });
    expect(app(ProxmoxApiClient::class)->version($c))->toBe('8.4.1');
    Http::assertSent(fn ($r) => $r->method() === 'GET' && $r->url() === 'https://[2001:db8::1]:8006/api2/json/version'
        && $r->hasHeader('Authorization', 'PVEAPIToken=monitor@pve!inventory=synthetic-test-secret'));
    Http::assertSentCount(1);
})->with([true, false]);

test('proxmox resources map raw metrics without per guest requests', function () {
    pveFake();
    $data = app(ProxmoxApiClient::class)->inventory(pveConnection());
    expect($data['nodes'])->toHaveCount(2)->and($data['guests'])->toHaveCount(2)
        ->and($data['guests']['qemu:100']['cpu_usage'])->toBe(0.5)
        ->and($data['guests']['qemu:100']['memory_total'])->toBe(2048);
    Http::assertSentCount(2);
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/cluster/resources') && $r->method() === 'GET');
});

test('proxmox HTTP errors never expose response or credentials and never retry', function (int $status, string $code) {
    Http::fake(['*' => Http::response('synthetic-test-secret PVEAPIToken= private error', $status)]);
    try {
        app(ProxmoxApiClient::class)->version(pveConnection());
        $this->fail('Expected safe exception');
    } catch (ProxmoxApiException $e) {
        expect($e->safeCode)->toBe($code)->and($e->getMessage())->not->toContain('synthetic-test-secret', 'PVEAPIToken', 'private error')
            ->and($e->getPrevious())->toBeNull();
    }
    Http::assertSentCount(1);
})->with([[401, 'auth'], [403, 'auth'], [500, 'http'], [302, 'http']]);

test('proxmox connection failures and timeouts are sanitized', function (string $message) {
    Http::fake(fn () => throw new ConnectionException($message));
    try {
        app(ProxmoxApiClient::class)->version(pveConnection());
        $this->fail();
    } catch (ProxmoxApiException $e) {
        expect($e->safeCode)->toBe('network')->and($e->getPrevious())->toBeNull()
            ->and($e->getMessage())->not->toContain('synthetic-test-secret', 'PVEAPIToken');
    }
})->with(['Connection refused synthetic-test-secret', 'cURL error 28 timeout PVEAPIToken=synthetic-test-secret']);

test('proxmox rejects malformed version payload', function ($body) {
    Http::fake(['*' => Http::response($body)]);
    expect(fn () => app(ProxmoxApiClient::class)->version(pveConnection()))->toThrow(ProxmoxApiException::class);
})->with(['json' => ['{'], 'missing data' => [[]], 'missing version' => [['data' => []]],
    'wrong type' => [['data' => ['version' => 42]]], 'errors' => [['data' => ['version' => '8.4'], 'errors' => ['secret']]]]);

test('proxmox rejects malformed inventory rows', function (string $field, mixed $value) {
    $rows = pveResources();
    $rows[2][$field] = $value;
    pveFake($rows);
    expect(fn () => app(ProxmoxApiClient::class)->inventory(pveConnection()))->toThrow(ProxmoxApiException::class);
})->with([['vmid', true], ['vmid', null], ['vmid', -1], ['vmid', 2.5], ['type', null], ['type', ''],
    ['node', 'absent'], ['node', null], ['status', null], ['cpu', 'bad'], ['cpu', -0.1], ['cpu', 1.1],
    ['mem', -1], ['maxmem', []], ['uptime', 1.5], ['maxcpu', 2147483648], ['template', 2], ['name', []]]);

test('proxmox rejects empty partial and duplicate inventory', function (string $variant) {
    $rows = pveResources();
    if ($variant === 'empty') {
        $rows = [];
    }
    if ($variant === 'partial') {
        array_shift($rows);
    }
    if ($variant === 'duplicate guest') {
        $rows[] = $rows[2];
    }
    if ($variant === 'duplicate numeric identity') {
        $duplicate = $rows[2];
        $duplicate['vmid'] = '+100';
        $rows[] = $duplicate;
    }
    if ($variant === 'duplicate node') {
        $rows[] = $rows[0];
    }
    pveFake($rows);
    expect(fn () => app(ProxmoxApiClient::class)->inventory(pveConnection()))->toThrow(ProxmoxApiException::class);
})->with(['empty', 'partial', 'duplicate guest', 'duplicate numeric identity', 'duplicate node']);

test('proxmox safely normalizes unfamiliar statuses and supports templates', function () {
    $rows = pveResources();
    $rows[0]['status'] = 'future';
    $rows[2]['status'] = 'suspended';
    $rows[3]['template'] = 1;
    pveFake($rows);
    $data = app(ProxmoxApiClient::class)->inventory(pveConnection());
    expect($data['nodes']['pve1']['status'])->toBe('unknown')
        ->and($data['guests']['qemu:100']['status'])->toBe('unknown')
        ->and($data['guests']['lxc:101']['template'])->toBeTrue();
});

test('proxmox inventory supports current and future resource shapes', function (string $shape) {
    pveCompatibilityFake($shape);
    $data = app(ProxmoxApiClient::class)->inventory(pveConnection());
    expect(array_keys($data['nodes']))->toBe(['proxmox'])
        ->and(array_keys($data['guests']))->toBe(['qemu:100', 'lxc:101']);
    Http::assertSentCount(2);
})->with(['9.1.1', '9.1.4', 'future']);

test('proxmox rejects resource rows without a nonempty string type', function (mixed $row) {
    pveFake([...pveResources(), $row]);
    expect(fn () => app(ProxmoxApiClient::class)->inventory(pveConnection()))->toThrow(ProxmoxApiException::class);
})->with(['scalar' => [42], 'missing' => [[]], 'null' => [['type' => null]],
    'empty' => [['type' => '']], 'number' => [['type' => 42]], 'array' => [['type' => []]]]);

test('proxmox ignores unknown resource fields before imported field validation', function () {
    pveFake([...pveResources(), ['type' => 'future', 'node' => [], 'status' => [], 'vmid' => -1, 'cpu' => -10]]);
    $data = app(ProxmoxApiClient::class)->inventory(pveConnection());
    expect($data['nodes'])->toHaveCount(2)->and($data['guests'])->toHaveCount(2);
});

test('proxmox keeps strict validation for every imported resource type', function (string $type, string $field, mixed $value) {
    $rows = pveResources();
    $index = array_search($type, array_column($rows, 'type'), true);
    $rows[$index][$field] = $value;
    pveFake($rows);
    expect(fn () => app(ProxmoxApiClient::class)->inventory(pveConnection()))->toThrow(ProxmoxApiException::class);
})->with(['node', 'qemu', 'lxc'])->with([
    ['node', null], ['node', 'bad/name'], ['node', 'absent'],
    ['status', []], ['status', ''], ['cpu', 'bad'], ['cpu', -0.1], ['cpu', 1.1], ['mem', -1],
]);

test('proxmox keeps strict guest identity name and template validation', function (string $type, string $field, mixed $value) {
    $rows = pveResources();
    $index = array_search($type, array_column($rows, 'type'), true);
    $rows[$index][$field] = $value;
    pveFake($rows);
    expect(fn () => app(ProxmoxApiClient::class)->inventory(pveConnection()))->toThrow(ProxmoxApiException::class);
})->with(['qemu', 'lxc'])->with([['vmid', null], ['vmid', -1], ['name', []], ['template', 2]]);
