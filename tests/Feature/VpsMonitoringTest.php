<?php

use App\Models\Event;
use App\Models\User;
use App\Models\Vps;
use App\Services\VpsHealthCheckService;
use App\Services\VpsMonitoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Helper: make a VPS with given status
function makeVps(string $name, string $status = 'unknown', bool $enabled = true): Vps
{
    return Vps::create([
        'name'       => $name,
        'ip_address' => '10.0.0.' . rand(1, 254),
        'check_port' => 22,
        'status'     => $status,
        'enabled'    => $enabled,
    ]);
}

// Helper: mock VpsHealthCheckService to return a given status
function mockCheck(string $returnStatus, int $responseMs = 10): void
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

// ─── 1. online → offline creates exactly 1 warning event ─────────────────────

test('online to offline creates exactly one warning event', function () {
    $vps = makeVps('VPS-A', 'online');
    mockCheck('offline');

    /** @var VpsMonitoringService $service */
    $service = app(VpsMonitoringService::class);
    $result  = $service->monitor($vps);

    expect($result['event_created'])->toBeTrue();
    expect(Event::count())->toBe(1);

    $event = Event::first();
    expect($event->severity)->toBe('warning');
    expect($event->type)->toBe('vps');
    expect($event->source_id)->toBe($vps->id);
});

// ─── 2. offline → offline does NOT create an event ───────────────────────────

test('offline to offline does not create an event', function () {
    $vps = makeVps('VPS-B', 'offline');
    mockCheck('offline');

    $result = app(VpsMonitoringService::class)->monitor($vps);

    expect($result['event_created'])->toBeFalse();
    expect(Event::count())->toBe(0);
});

// ─── 3. offline → online creates exactly 1 recovery info event ───────────────

test('offline to online creates exactly one recovery info event', function () {
    $vps = makeVps('VPS-C', 'offline');
    mockCheck('online', 14);

    $result = app(VpsMonitoringService::class)->monitor($vps);

    expect($result['event_created'])->toBeTrue();
    expect(Event::count())->toBe(1);

    $event = Event::first();
    expect($event->severity)->toBe('info');
    expect($event->type)->toBe('vps');
    expect($event->source_id)->toBe($vps->id);
    expect($event->message)->toContain('14 ms');
});

// ─── 4. online → online does NOT create an event ─────────────────────────────

test('online to online does not create an event', function () {
    $vps = makeVps('VPS-D', 'online');
    mockCheck('online');

    $result = app(VpsMonitoringService::class)->monitor($vps);

    expect($result['event_created'])->toBeFalse();
    expect(Event::count())->toBe(0);
});

// ─── 5. unknown → offline creates a warning event ────────────────────────────

test('unknown to offline creates a warning event', function () {
    $vps = makeVps('VPS-E', 'unknown');
    mockCheck('offline');

    $result = app(VpsMonitoringService::class)->monitor($vps);

    expect($result['event_created'])->toBeTrue();
    expect(Event::count())->toBe(1);
    expect(Event::first()->severity)->toBe('warning');
});

// ─── 6. unknown → online does NOT create a warning/recovery event ─────────────

test('unknown to online does not create an event', function () {
    $vps = makeVps('VPS-F', 'unknown');
    mockCheck('online');

    $result = app(VpsMonitoringService::class)->monitor($vps);

    expect($result['event_created'])->toBeFalse();
    expect(Event::count())->toBe(0);
});

// ─── 7. monitor:vps command checks only enabled VPS ──────────────────────────

test('monitor:vps command checks only enabled vps', function () {
    $enabled  = makeVps('Enabled-VPS',  'unknown', true);
    $disabled = makeVps('Disabled-VPS', 'unknown', false);

    $callCount = 0;
    $mock = $this->mock(VpsHealthCheckService::class);
    $mock->shouldReceive('check')
        ->andReturnUsing(function (Vps $v) use (&$callCount) {
            $callCount++;
            $v->update(['status' => 'online', 'last_checked_at' => now(), 'last_response_ms' => 5]);
            return ['status' => 'online', 'response_ms' => 5];
        });

    $this->artisan('monitor:vps')->assertExitCode(0);

    expect($callCount)->toBe(1);
    $this->assertDatabaseHas('vps', ['id' => $disabled->id, 'status' => 'unknown']);
});

// ─── 8. Error in one VPS does not prevent processing the rest ────────────────

test('error in one vps does not prevent others from being processed', function () {
    $vps1 = makeVps('VPS-OK',    'unknown', true);
    $vps2 = makeVps('VPS-Error', 'unknown', true);

    $callCount = 0;
    $mock = $this->mock(VpsHealthCheckService::class);
    $mock->shouldReceive('check')
        ->andReturnUsing(function (Vps $v) use ($vps2, &$callCount) {
            $callCount++;
            if ($v->id === $vps2->id) {
                throw new \RuntimeException('Simulated TCP error');
            }
            $v->update(['status' => 'online', 'last_checked_at' => now(), 'last_response_ms' => 5]);
            return ['status' => 'online', 'response_ms' => 5];
        });

    $this->artisan('monitor:vps')->assertExitCode(0);

    expect($callCount)->toBe(2);
    $this->assertDatabaseHas('vps', ['id' => $vps1->id, 'status' => 'online']);
    // vps2 was not updated because exception was thrown before update
    $this->assertDatabaseHas('vps', ['id' => $vps2->id, 'status' => 'unknown']);
});

// ─── 9. Dashboard receives recent events from DB ─────────────────────────────

test('dashboard receives recent events from database', function () {
    $user = User::factory()->create();

    Event::create([
        'type'        => 'vps',
        'severity'    => 'warning',
        'title'       => 'VPS Test недоступен',
        'message'     => 'TCP connect к 10.0.0.1:22 не удался.',
        'source_id'   => null,
        'occurred_at' => now()->subMinutes(5),
    ]);

    Event::create([
        'type'        => 'vps',
        'severity'    => 'info',
        'title'       => 'VPS Test восстановлен',
        'message'     => 'TCP connect снова успешен, отклик 12 ms.',
        'source_id'   => null,
        'occurred_at' => now(),
    ]);

    $response = $this->actingAs($user)->get('/');

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('Dashboard')
        ->has('dashboard.recentEvents', 2)
    );
});

// ─── 10. Events are sorted newest first ──────────────────────────────────────

test('recent events are sorted newest first', function () {
    $user = User::factory()->create();

    Event::create([
        'type'        => 'vps',
        'severity'    => 'warning',
        'title'       => 'Старое событие',
        'message'     => 'msg',
        'occurred_at' => now()->subHour(),
    ]);

    Event::create([
        'type'        => 'vps',
        'severity'    => 'info',
        'title'       => 'Новое событие',
        'message'     => 'msg',
        'occurred_at' => now(),
    ]);

    $response = $this->actingAs($user)->get('/');

    $response->assertInertia(fn ($page) => $page
        ->where('dashboard.recentEvents.0.title', 'Новое событие')
        ->where('dashboard.recentEvents.1.title', 'Старое событие')
    );
});

// ─── 11. Manual check button creates events via monitoring service ────────────

test('manual check creates event on status transition via monitoring service', function () {
    $user = User::factory()->create();

    $vps = makeVps('Manual-VPS', 'online');

    $mock = $this->mock(VpsHealthCheckService::class);
    $mock->shouldReceive('check')
        ->once()
        ->andReturnUsing(function (Vps $v) {
            $v->update(['status' => 'offline', 'last_checked_at' => now(), 'last_response_ms' => 3000]);
            return ['status' => 'offline', 'response_ms' => 3000];
        });

    $response = $this->actingAs($user)->post("/vps/{$vps->id}/check");

    $response->assertRedirect('/vps');
    expect(Event::count())->toBe(1);
    expect(Event::first()->severity)->toBe('warning');
});

// ─── 12. check-all creates events only on status transitions ─────────────────

test('check all creates events only for vps that change status', function () {
    $user = User::factory()->create();

    $stable  = makeVps('VPS-Stable',  'online');   // online → online: no event
    $failing = makeVps('VPS-Failing', 'online');   // online → offline: event

    $mock = $this->mock(VpsHealthCheckService::class);
    $mock->shouldReceive('check')
        ->andReturnUsing(function (Vps $v) use ($failing) {
            if ($v->id === $failing->id) {
                $v->update(['status' => 'offline', 'last_checked_at' => now(), 'last_response_ms' => 3000]);
                return ['status' => 'offline', 'response_ms' => 3000];
            }
            $v->update(['status' => 'online', 'last_checked_at' => now(), 'last_response_ms' => 10]);
            return ['status' => 'online', 'response_ms' => 10];
        });

    $response = $this->actingAs($user)->post('/vps/check-all');

    $response->assertRedirect('/vps');
    expect(Event::count())->toBe(1);
    expect(Event::first()->source_id)->toBe($failing->id);
});
