<?php

use App\Models\Event;
use App\Models\Vps;
use App\Services\MaxNotifier;
use App\Services\VpsHealthCheckService;
use App\Services\VpsMonitoringService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

// ─── Helpers ──────────────────────────────────────────────────────────────────

function maxVps(string $name, string $status = 'unknown'): Vps
{
    return Vps::create([
        'name'       => $name,
        'ip_address' => '10.0.0.' . rand(1, 254),
        'check_port' => 22,
        'status'     => $status,
        'enabled'    => true,
    ]);
}

function maxMockCheck(string $returnStatus, int $responseMs = 10): void
{
    $mock = test()->mock(VpsHealthCheckService::class);
    $mock->shouldReceive('check')
        ->andReturnUsing(function (Vps $v) use ($returnStatus, $responseMs) {
            $v->update([
                'status'           => $returnStatus,
                'last_checked_at'  => now(),
                'last_response_ms' => $responseMs,
            ]);
            return ['status' => $returnStatus, 'response_ms' => $responseMs];
        });
}

/**
 * Build a VpsMonitoringService with a real (or configured) MaxNotifier injected directly,
 * bypassing the IoC container to ensure config overrides are honoured.
 */
function serviceWithNotifier(MaxNotifier $notifier): VpsMonitoringService
{
    return new VpsMonitoringService(app(VpsHealthCheckService::class), $notifier);
}

/**
 * Create a MaxNotifier with test-safe fake credentials pre-configured.
 */
function fakeConfiguredNotifier(): MaxNotifier
{
    config([
        'services.max.bot_token' => 'test-fake-token',
        'services.max.user_id'   => '123456789',
        'services.max.api_url'   => 'https://platform-api2.max.ru',
    ]);
    return new MaxNotifier();
}

// ─── A. online → offline ──────────────────────────────────────────────────────
// Integration: VpsMonitoringService must call MaxNotifier::sendDown on transition.

test('A: online to offline calls MaxNotifier sendDown once', function () {
    $vps = maxVps('VPS-A', 'online');

    $notifier = test()->mock(MaxNotifier::class);
    $notifier->shouldReceive('sendDown')->once();
    $notifier->shouldReceive('sendRecovery')->never();

    maxMockCheck('offline');

    $result = app(VpsMonitoringService::class)->monitor($vps);

    expect($result['event_created'])->toBeTrue();
    expect(Event::count())->toBe(1);
    expect(Event::first()->severity)->toBe('warning');
});

// ─── B. unknown → offline ─────────────────────────────────────────────────────

test('B: unknown to offline calls MaxNotifier sendDown once', function () {
    $vps = maxVps('VPS-B', 'unknown');

    $notifier = test()->mock(MaxNotifier::class);
    $notifier->shouldReceive('sendDown')->once();
    $notifier->shouldReceive('sendRecovery')->never();

    maxMockCheck('offline');

    $result = app(VpsMonitoringService::class)->monitor($vps);

    expect($result['event_created'])->toBeTrue();
    expect(Event::count())->toBe(1);
});

// ─── C. offline → offline ─────────────────────────────────────────────────────

test('C: offline to offline calls MaxNotifier never', function () {
    $vps = maxVps('VPS-C', 'offline');

    $notifier = test()->mock(MaxNotifier::class);
    $notifier->shouldReceive('sendDown')->never();
    $notifier->shouldReceive('sendRecovery')->never();

    maxMockCheck('offline');

    $result = app(VpsMonitoringService::class)->monitor($vps);

    expect($result['event_created'])->toBeFalse();
    expect(Event::count())->toBe(0);
});

// ─── D. offline → online ──────────────────────────────────────────────────────

test('D: offline to online calls MaxNotifier sendRecovery once', function () {
    $vps = maxVps('VPS-D', 'offline');

    $notifier = test()->mock(MaxNotifier::class);
    $notifier->shouldReceive('sendDown')->never();
    $notifier->shouldReceive('sendRecovery')->once();

    maxMockCheck('online', 14);

    $result = app(VpsMonitoringService::class)->monitor($vps);

    expect($result['event_created'])->toBeTrue();
    expect(Event::first()->severity)->toBe('info');
});

// ─── E. online → online ───────────────────────────────────────────────────────

test('E: online to online calls MaxNotifier never', function () {
    $vps = maxVps('VPS-E', 'online');

    $notifier = test()->mock(MaxNotifier::class);
    $notifier->shouldReceive('sendDown')->never();
    $notifier->shouldReceive('sendRecovery')->never();

    maxMockCheck('online');

    $result = app(VpsMonitoringService::class)->monitor($vps);

    expect($result['event_created'])->toBeFalse();
});

// ─── F. unknown → online ──────────────────────────────────────────────────────

test('F: unknown to online calls MaxNotifier never', function () {
    $vps = maxVps('VPS-F', 'unknown');

    $notifier = test()->mock(MaxNotifier::class);
    $notifier->shouldReceive('sendDown')->never();
    $notifier->shouldReceive('sendRecovery')->never();

    maxMockCheck('online');

    $result = app(VpsMonitoringService::class)->monitor($vps);

    expect($result['event_created'])->toBeFalse();
});

// ─── G. MAX API returns 500 ───────────────────────────────────────────────────
// Unit: MaxNotifier::sendDown does not throw on 500.
// Integration: full monitoring completes, Event is created.

test('G-unit: MaxNotifier sendDown does not throw on MAX 500 response', function () {
    Http::fake(['platform-api2.max.ru/*' => Http::response(['error' => 'server error'], 500)]);

    $notifier = fakeConfiguredNotifier();
    $vps = maxVps('VPS-G-unit', 'online');

    // Must not throw — catch is inside MaxNotifier
    expect(fn () => $notifier->sendDown($vps))->not->toThrow(\Throwable::class);
    Http::assertSentCount(1);
});

test('G: full monitoring with MAX API 500 still creates event successfully', function () {
    Http::fake(['platform-api2.max.ru/*' => Http::response(['error' => 'server error'], 500)]);

    $vps = maxVps('VPS-G', 'online');
    maxMockCheck('offline');

    $service = serviceWithNotifier(fakeConfiguredNotifier());
    $result  = $service->monitor($vps);

    expect($vps->fresh()->status)->toBe('offline');
    expect($result['event_created'])->toBeTrue();
    expect(Event::count())->toBe(1);
    Http::assertSentCount(1);
});

// ─── H. ConnectionException ───────────────────────────────────────────────────

test('H-unit: MaxNotifier sendDown does not throw on connection exception', function () {
    Http::fake([
        'platform-api2.max.ru/*' => function () {
            throw new ConnectionException('Connection refused');
        },
    ]);

    $notifier = fakeConfiguredNotifier();
    $vps = maxVps('VPS-H-unit', 'online');

    expect(fn () => $notifier->sendDown($vps))->not->toThrow(\Throwable::class);
});

test('H: full monitoring with MAX connection exception still creates event', function () {
    Http::fake([
        'platform-api2.max.ru/*' => function () {
            throw new ConnectionException('Connection refused');
        },
    ]);

    $vps = maxVps('VPS-H', 'online');
    maxMockCheck('offline');

    $service = serviceWithNotifier(fakeConfiguredNotifier());
    $result  = $service->monitor($vps);

    expect($vps->fresh()->status)->toBe('offline');
    expect($result['event_created'])->toBeTrue();
    expect(Event::count())->toBe(1);
});

// ─── I. MAX not configured ────────────────────────────────────────────────────

test('I: MaxNotifier makes no HTTP request when bot_token is missing', function () {
    Http::fake();

    config([
        'services.max.bot_token' => null,
        'services.max.user_id'   => '123456789',
    ]);

    $notifier = new MaxNotifier();
    $vps = maxVps('VPS-I', 'online');

    $notifier->sendDown($vps);

    Http::assertNothingSent();
});

test('I-b: MaxNotifier makes no HTTP request when user_id is missing', function () {
    Http::fake();

    config([
        'services.max.bot_token' => 'test-token',
        'services.max.user_id'   => null,
    ]);

    $notifier = new MaxNotifier();
    $vps = maxVps('VPS-Ib', 'online');

    $notifier->sendDown($vps);

    Http::assertNothingSent();
});

// ─── Message content ──────────────────────────────────────────────────────────

test('DOWN message contains VPS name, host:port, and correct user_id in URL', function () {
    Http::fake(['platform-api2.max.ru/*' => Http::response([], 200)]);

    $notifier = fakeConfiguredNotifier();
    $vps = maxVps('My-Test-Server', 'online');

    $notifier->sendDown($vps);

    Http::assertSent(function ($request) use ($vps) {
        $body = json_decode($request->body(), true);
        return str_contains($request->url(), '/messages')
            && str_contains($request->url(), 'user_id=123456789')
            && ($request->header('Authorization')[0] ?? '') === 'test-fake-token'
            && str_contains($body['text'] ?? '', 'My-Test-Server')
            && str_contains($body['text'] ?? '', 'недоступен')
            && str_contains($body['text'] ?? '', (string) $vps->check_port);
    });
});

test('RECOVERY message contains VPS name, host:port, and correct user_id in URL', function () {
    Http::fake(['platform-api2.max.ru/*' => Http::response([], 200)]);

    $notifier = fakeConfiguredNotifier();
    $vps = maxVps('My-Test-Server', 'offline');

    $notifier->sendRecovery($vps);

    Http::assertSent(function ($request) use ($vps) {
        $body = json_decode($request->body(), true);
        return str_contains($request->url(), '/messages')
            && str_contains($request->url(), 'user_id=123456789')
            && str_contains($body['text'] ?? '', 'My-Test-Server')
            && str_contains($body['text'] ?? '', 'доступен')
            && str_contains($body['text'] ?? '', (string) $vps->check_port);
    });
});
