<?php

use App\Models\Event;
use App\Models\LocalDevice;
use App\Models\User;
use App\Services\LocalDeviceHealthCheckService;
use App\Services\LocalDeviceMonitoringService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/../Support/LocalInfrastructure.php';

beforeEach(function () {
    $this->travelTo(now()->startOfSecond());
    config(['services.max.bot_token' => 'fake-local-token', 'services.max.user_id' => '123456', 'services.max.api_url' => 'https://max.invalid']);
    Http::preventStrayRequests();
    localHttpFake(['https://max.invalid/*' => Http::response(['success' => true])]);
    localFakeCheck('offline');
});

test('first offline starts persisted grace timer and exact two minute boundary confirms once', function (string $initial) {
    $device = localDevice(['status' => $initial]);
    localMonitor($device);
    expect($device->fresh()->status)->toBe('offline')->and($device->fresh()->failure_started_at)->not->toBeNull()
        ->and($device->fresh()->incident_confirmed_at)->toBeNull()->and(Event::count())->toBe(0);
    Http::assertNothingSent();
    $this->travel(119)->seconds();
    localMonitor($device);
    expect($device->fresh()->incident_confirmed_at)->toBeNull()->and(Event::count())->toBe(0);
    Http::assertNothingSent();
    $this->travel(1)->seconds();
    expect(localMonitor($device)['event_created'])->toBeTrue();
    $device->refresh();
    expect($device->incident_confirmed_at)->not->toBeNull()->and($device->incident_notified_at)->not->toBeNull();
    $event = Event::sole();
    expect($event->severity)->toBe('warning')->and($event->type)->toBe('local_device')->and($event->source_id)->toBe($device->id)
        ->and($event->message)->toContain($device->name, $device->location->name, '192.0.2.10:8006');
    for ($i = 0; $i < 3; $i++) { $this->travel(1)->minutes(); localMonitor($device); }
    expect(Event::count())->toBe(1);
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->url() === 'https://max.invalid/messages?user_id=123456'
        && $request->hasHeader('Authorization', 'fake-local-token') && str_contains($request['text'], '🔴')
        && str_contains($request['text'], $device->location->name));
})->with(['online', 'unknown']);

test('recovery before confirmation and initial online are silent', function () {
    $device = localDevice();
    localFakeCheck('online'); localMonitor($device);
    localFakeCheck('offline'); localMonitor($device);
    $this->travel(119)->seconds();
    localFakeCheck('online'); localMonitor($device);
    expect($device->fresh()->failure_started_at)->toBeNull()->and($device->fresh()->incident_confirmed_at)->toBeNull()
        ->and($device->fresh()->recovery_pending_at)->toBeNull()->and(Event::count())->toBe(0);
    Http::assertNothingSent();
    localFakeCheck('offline'); localMonitor($device);
    expect($device->fresh()->failure_started_at->equalTo(now()))->toBeTrue();
});

test('confirmed recovery produces one info event one green delivery and clears state', function () {
    $device = localDevice(['host' => 'fd00::1']);
    localMonitor($device); $this->travel(2)->minutes(); localMonitor($device);
    localFakeCheck('online'); localMonitor($device); localMonitor($device);
    expect(Event::count())->toBe(2)->and(Event::orderByDesc('id')->first()->severity)->toBe('info')
        ->and(Event::orderBy('id')->first()->resolved_at)->not->toBeNull();
    $device->refresh();
    foreach (['failure_started_at', 'incident_confirmed_at', 'incident_notified_at', 'recovery_pending_at'] as $field) expect($device->$field)->toBeNull();
    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => str_contains($request['text'], '🟢') && str_contains($request['text'], '[fd00::1]:8006'));
});

test('failed MAX down is retried without duplicate event or rollback of factual state', function (string $failure) {
    localHttpFake(['https://max.invalid/*' => function () use ($failure) {
        if ($failure === 'exception') throw new \Illuminate\Http\Client\ConnectionException('Fake MAX transport failure');
        return $failure === 'http' ? Http::response([], 500) : Http::response(['success' => false], 200);
    }]);
    $device = localDevice();
    localMonitor($device); $this->travel(2)->minutes(); localMonitor($device);
    expect($device->fresh()->status)->toBe('offline')->and($device->fresh()->last_checked_at)->not->toBeNull()
        ->and($device->fresh()->incident_confirmed_at)->not->toBeNull()->and($device->fresh()->incident_notified_at)->toBeNull()
        ->and(Event::count())->toBe(1);
    localHttpFake(['https://max.invalid/*' => Http::response(['success' => true])]);
    localMonitor($device);
    expect($device->fresh()->incident_notified_at)->not->toBeNull()->and(Event::count())->toBe(1);
    $count = count(Http::recorded());
    localMonitor($device);
    expect(count(Http::recorded()))->toBe($count);
})->with(['http', 'application', 'exception']);

test('failed recovery persists pending state retries on online and never duplicates event', function () {
    $device = localDevice();
    localMonitor($device); $this->travel(2)->minutes(); localMonitor($device);
    localHttpFake(['https://max.invalid/*' => Http::response([], 503)]);
    localFakeCheck('online'); localMonitor($device);
    expect($device->fresh()->status)->toBe('online')->and($device->fresh()->incident_confirmed_at)->toBeNull()
        ->and($device->fresh()->recovery_pending_at)->not->toBeNull()->and(Event::count())->toBe(2);
    localHttpFake(['https://max.invalid/*' => Http::response(['success' => true])]);
    localMonitor($device);
    expect($device->fresh()->recovery_pending_at)->toBeNull()->and(Event::count())->toBe(2);
    $count = count(Http::recorded()); localMonitor($device);
    expect(count(Http::recorded()))->toBe($count);
});

test('confirmed recovery is reported even if down delivery never succeeded', function () {
    localHttpFake(['https://max.invalid/*' => Http::response([], 500)]);
    $device = localDevice(); localMonitor($device); $this->travel(2)->minutes(); localMonitor($device);
    localHttpFake(['https://max.invalid/*' => Http::response(['success' => true])]);
    localFakeCheck('online'); localMonitor($device);
    expect(Event::count())->toBe(2)->and($device->fresh()->recovery_pending_at)->toBeNull();
    Http::assertSent(fn ($request) => str_contains($request['text'], '🟢'));
});

test('new outage discards stale recovery and starts a fresh two minute incident', function () {
    $device = localDevice(); localMonitor($device); $this->travel(2)->minutes(); localMonitor($device);
    localHttpFake(['https://max.invalid/*' => Http::response([], 500)]);
    localFakeCheck('online'); localMonitor($device);
    localFakeCheck('offline'); localMonitor($device);
    expect($device->fresh()->recovery_pending_at)->toBeNull()->and($device->fresh()->incident_confirmed_at)->toBeNull()->and(Event::count())->toBe(2);
    localHttpFake(['https://max.invalid/*' => Http::response(['success' => true])]);
    $this->travel(2)->minutes(); localMonitor($device);
    expect(Event::count())->toBe(3)->and($device->fresh()->incident_notified_at)->not->toBeNull();
});

test('missing credentials never count as successful delivery and later configuration retries', function () {
    config(['services.max.bot_token' => '', 'services.max.user_id' => '']);
    $device = localDevice(); localMonitor($device); $this->travel(2)->minutes(); localMonitor($device);
    expect($device->fresh()->incident_notified_at)->toBeNull(); Http::assertNothingSent();
    config(['services.max.bot_token' => 'fake-local-token', 'services.max.user_id' => '123456']);
    localMonitor($device); Http::assertSentCount(1);
});

test('manual checks batch and scheduler share quiet semantics and duplicate suppression', function (string $mode) {
    $device = localDevice();
    $this->actingAs(User::factory()->create());
    $check = function () use ($mode, $device) {
        if ($mode === 'scheduled') $this->artisan('monitor:local-devices')->assertExitCode(0);
        else $this->post($mode === 'single' ? "/local-devices/{$device->id}/check" : '/local-devices/check-all')->assertRedirect('/local-infrastructure');
    };
    $check(); $this->travel(2)->minutes(); $check(); $check();
    expect(Event::count())->toBe(1); Http::assertSentCount(1);
    localFakeCheck('online'); $check(); $check();
    expect(Event::count())->toBe(2); Http::assertSentCount(2);
})->with(['single', 'batch', 'scheduled']);

test('disabled devices and locations are skipped by batch but manual diagnostics are silent', function (bool $locationDisabled) {
    $device = localDevice(['enabled' => $locationDisabled, 'location_id' => localLocation(['enabled' => !$locationDisabled])->id]);
    $this->actingAs(User::factory()->create());
    $this->artisan('monitor:local-devices')->expectsOutput('checked=0 errors=0')->assertExitCode(0);
    $this->post('/local-devices/check-all')->assertRedirect();
    expect($device->fresh()->last_checked_at)->toBeNull();
    $this->post("/local-devices/{$device->id}/check")->assertRedirect();
    $this->travel(3)->minutes(); $this->post("/local-devices/{$device->id}/check")->assertRedirect();
    expect($device->fresh()->status)->toBe('offline')->and($device->fresh()->failure_started_at)->toBeNull();
    localFakeCheck('online'); $this->post("/local-devices/{$device->id}/check")->assertRedirect();
    expect(Event::count())->toBe(0)->and($device->fresh()->status)->toBe('online'); Http::assertNothingSent();
})->with([false, true]);

test('per device throwable does not stop batch and clears unconfirmed continuity', function (bool $scheduled) {
    $a = localDevice(['failure_started_at' => now()->subMinutes(5)]); $b = localDevice();
    $this->mock(LocalDeviceHealthCheckService::class)->shouldReceive('check')->twice()->andReturnUsing(function ($device) use ($a) {
        if ($device->id === $a->id) throw new RuntimeException('Fake checker exception');
        $device->update(['status' => 'online', 'last_checked_at' => now(), 'last_response_ms' => 1]);
        return ['status' => 'online', 'response_ms' => 1];
    });
    if ($scheduled) $this->artisan('monitor:local-devices')->expectsOutput('checked=1 errors=1')->assertExitCode(1);
    else $this->actingAs(User::factory()->create())->post('/local-devices/check-all')->assertSessionHasErrors('check');
    expect($a->fresh()->status)->toBe('unknown')->and($a->fresh()->failure_started_at)->toBeNull()
        ->and($b->fresh()->status)->toBe('online');
})->with([false, true]);

test('MAX failure for one device does not prevent another device from being checked', function () {
    localHttpFake(['https://max.invalid/*' => Http::response([], 500)]);
    $a = localDevice(); $b = localDevice();
    app(LocalDeviceMonitoringService::class)->checkAll(); $this->travel(2)->minutes();
    expect(app(LocalDeviceMonitoringService::class)->checkAll())->toBe(['checked' => 2, 'errors' => 0]);
    expect(Event::count())->toBe(2)->and($a->fresh()->incident_confirmed_at)->not->toBeNull()
        ->and($b->fresh()->incident_confirmed_at)->not->toBeNull();
});

test('stale selected model cannot monitor a device disabled after selection', function () {
    $device = localDevice(); LocalDevice::whereKey($device->id)->update(['enabled' => false]);
    expect(app(LocalDeviceMonitoringService::class)->monitor($device)['skipped'])->toBeTrue();
    expect($device->fresh()->last_checked_at)->toBeNull();
});

test('event title fits PostgreSQL varchar and message keeps complete context', function () {
    $device = localDevice(['name' => str_repeat('я', 255)]);
    localMonitor($device); $this->travel(2)->minutes(); localMonitor($device);
    expect(mb_strlen(Event::sole()->title))->toBeLessThanOrEqual(255)->and(Event::sole()->message)->toContain($device->name);
});

test('scheduler retains existing intervals and locks without background execution', function () {
    $this->artisan('schedule:list')->assertExitCode(0);
    $events = collect(app(Schedule::class)->events());
    foreach (['monitor:local-devices' => '* * * * *', 'monitor:vps' => '* * * * *', 'monitor:websites' => '*/5 * * * *'] as $command => $expression) {
        $event = $events->first(fn ($e) => str_contains($e->command ?? '', $command));
        expect($event)->not->toBeNull()->and($event->expression)->toBe($expression)
            ->and($event->withoutOverlapping)->toBeTrue()->and($event->expiresAt)->toBe(10)->and($event->runInBackground)->toBeFalse();
    }
});
