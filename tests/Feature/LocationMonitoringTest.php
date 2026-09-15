<?php

use App\Models\Event;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/../Support/LocationConnectivity.php';

beforeEach(function () {
    $this->travelTo(now()->startOfSecond());
    config(['services.max.bot_token' => 'fake-local-token', 'services.max.user_id' => '123456', 'services.max.api_url' => 'https://max.invalid']);
    Http::preventStrayRequests();
    localHttpFake(['https://max.invalid/*' => Http::response(['success' => true])]);
    locationFakeCheck('offline');
});

test('first offline starts persisted grace timer and exact two minute boundary confirms once', function (string $initial) {
    $location = probeLocation(['status' => $initial]);
    locationMonitor($location);
    expect($location->fresh()->status)->toBe('offline')->and($location->fresh()->failure_started_at)->not->toBeNull()
        ->and($location->fresh()->incident_confirmed_at)->toBeNull()->and(Event::count())->toBe(0);
    Http::assertNothingSent();
    $this->travel(119)->seconds();
    locationMonitor($location);
    expect($location->fresh()->incident_confirmed_at)->toBeNull()->and(Event::count())->toBe(0);
    Http::assertNothingSent();
    $this->travel(1)->seconds();
    expect(locationMonitor($location)['event_created'])->toBeTrue();
    $location->refresh();
    expect($location->incident_confirmed_at)->not->toBeNull()->and($location->incident_notified_at)->not->toBeNull();
    $event = Event::sole();
    expect($event->severity)->toBe('warning')->and($event->type)->toBe('location')->and($event->source_id)->toBe($location->id)
        ->and($event->message)->toContain($location->name, '192.0.2.10:8006');
    for ($i = 0; $i < 3; $i++) {
        $this->travel(1)->minutes();
        locationMonitor($location);
    }
    expect(Event::count())->toBe(1);
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->url() === 'https://max.invalid/messages?user_id=123456'
        && $request->hasHeader('Authorization', 'fake-local-token') && str_contains($request['text'], '🔴')
        && str_contains($request['text'], $location->name));
})->with(['online', 'unknown']);

test('recovery before confirmation and initial online are silent', function () {
    $location = probeLocation();
    locationFakeCheck('online');
    locationMonitor($location);
    locationFakeCheck('offline');
    locationMonitor($location);
    $this->travel(119)->seconds();
    locationFakeCheck('online');
    locationMonitor($location);
    expect($location->fresh()->failure_started_at)->toBeNull()->and($location->fresh()->incident_confirmed_at)->toBeNull()
        ->and($location->fresh()->recovery_pending_at)->toBeNull()->and(Event::count())->toBe(0);
    Http::assertNothingSent();
    locationFakeCheck('offline');
    locationMonitor($location);
    expect($location->fresh()->failure_started_at->equalTo(now()))->toBeTrue();
});

test('confirmed recovery produces one info event one green delivery and clears state', function () {
    $location = probeLocation(['probe_host' => 'fd00::1']);
    locationMonitor($location);
    $this->travel(2)->minutes();
    locationMonitor($location);
    locationFakeCheck('online');
    locationMonitor($location);
    locationMonitor($location);
    expect(Event::count())->toBe(2)->and(Event::orderByDesc('id')->first()->severity)->toBe('info')
        ->and(Event::orderBy('id')->first()->resolved_at)->not->toBeNull();
    $location->refresh();
    foreach (['failure_started_at', 'incident_confirmed_at', 'incident_notified_at', 'recovery_pending_at'] as $field) {
        expect($location->$field)->toBeNull();
    }
    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => str_contains($request['text'], '🟢') && str_contains($request['text'], '[fd00::1]:8006'));
});

test('failed MAX down is retried without duplicate event or rollback of factual state', function (string $failure) {
    localHttpFake(['https://max.invalid/*' => function () use ($failure) {
        if ($failure === 'exception') {
            throw new ConnectionException('Fake MAX transport failure');
        }

        return $failure === 'http' ? Http::response([], 500) : Http::response(['success' => false], 200);
    }]);
    $location = probeLocation();
    locationMonitor($location);
    $this->travel(2)->minutes();
    locationMonitor($location);
    expect($location->fresh()->status)->toBe('offline')->and($location->fresh()->last_checked_at)->not->toBeNull()
        ->and($location->fresh()->incident_confirmed_at)->not->toBeNull()->and($location->fresh()->incident_notified_at)->toBeNull()
        ->and(Event::count())->toBe(1);
    localHttpFake(['https://max.invalid/*' => Http::response(['success' => true])]);
    locationMonitor($location);
    expect($location->fresh()->incident_notified_at)->not->toBeNull()->and(Event::count())->toBe(1);
    $count = count(Http::recorded());
    locationMonitor($location);
    expect(count(Http::recorded()))->toBe($count);
})->with(['http', 'application', 'exception']);

test('failed recovery persists pending state retries on online and never duplicates event', function () {
    $location = probeLocation();
    locationMonitor($location);
    $this->travel(2)->minutes();
    locationMonitor($location);
    localHttpFake(['https://max.invalid/*' => Http::response([], 503)]);
    locationFakeCheck('online');
    locationMonitor($location);
    expect($location->fresh()->status)->toBe('online')->and($location->fresh()->incident_confirmed_at)->toBeNull()
        ->and($location->fresh()->recovery_pending_at)->not->toBeNull()->and(Event::count())->toBe(2);
    localHttpFake(['https://max.invalid/*' => Http::response(['success' => true])]);
    locationMonitor($location);
    expect($location->fresh()->recovery_pending_at)->toBeNull()->and(Event::count())->toBe(2);
    $count = count(Http::recorded());
    locationMonitor($location);
    expect(count(Http::recorded()))->toBe($count);
});

test('confirmed recovery keeps Event but no orphan green if down delivery never succeeded', function () {
    localHttpFake(['https://max.invalid/*' => Http::response([], 500)]);
    $location = probeLocation();
    locationMonitor($location);
    $this->travel(2)->minutes();
    locationMonitor($location);
    localHttpFake(['https://max.invalid/*' => Http::response(['success' => true])]);
    locationFakeCheck('online');
    locationMonitor($location);
    expect(Event::count())->toBe(2)->and($location->fresh()->recovery_pending_at)->toBeNull();
    Http::assertNothingSent();
});

test('new outage discards stale recovery and starts a fresh two minute incident', function () {
    $location = probeLocation();
    locationMonitor($location);
    $this->travel(2)->minutes();
    locationMonitor($location);
    localHttpFake(['https://max.invalid/*' => Http::response([], 500)]);
    locationFakeCheck('online');
    locationMonitor($location);
    locationFakeCheck('offline');
    locationMonitor($location);
    expect($location->fresh()->recovery_pending_at)->toBeNull()->and($location->fresh()->incident_confirmed_at)->toBeNull()->and(Event::count())->toBe(2);
    localHttpFake(['https://max.invalid/*' => Http::response(['success' => true])]);
    $this->travel(2)->minutes();
    locationMonitor($location);
    expect(Event::count())->toBe(3)->and($location->fresh()->incident_notified_at)->not->toBeNull();
});

test('missing credentials never count as successful delivery and later configuration retries', function () {
    config(['services.max.bot_token' => '', 'services.max.user_id' => '']);
    $location = probeLocation();
    locationMonitor($location);
    $this->travel(2)->minutes();
    locationMonitor($location);
    expect($location->fresh()->incident_notified_at)->toBeNull();
    Http::assertNothingSent();
    config(['services.max.bot_token' => 'fake-local-token', 'services.max.user_id' => '123456']);
    locationMonitor($location);
    Http::assertSentCount(1);
});
