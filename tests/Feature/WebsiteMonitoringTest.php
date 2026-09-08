<?php

use App\Models\Event;
use App\Models\Website;
use App\Services\MaxNotifier;
use App\Services\WebsiteMonitoringService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->travelTo(now()->startOfSecond());
});

test('website monitoring follows the transition matrix', function (?string $previous, int $code, ?string $severity, ?string $notification) {
    Http::fake(['https://example.test' => Http::response('', $code)]);
    $site = Website::create([
        'name' => 'Matrix', 'url' => 'https://example.test', 'status' => $previous ?? 'unknown',
        'last_http_status' => 404, 'last_response_ms' => 999, 'last_checked_at' => now()->subHour(),
    ]);
    // The schema is non-nullable; exercise legacy null normalization in memory.
    if ($previous === null) {
        $site->status = null;
    }
    $notifier = $this->mock(MaxNotifier::class);
    $notifier->shouldReceive('sendWebsiteDown')->times($notification === 'down' ? 1 : 0)
        ->with(Mockery::on(fn ($value) => $value->id === $site->id && $value->last_http_status === $code));
    $notifier->shouldReceive('sendWebsiteRecovery')->times($notification === 'recovery' ? 1 : 0)
        ->with(Mockery::on(fn ($value) => $value->id === $site->id && $value->last_http_status === $code));

    $result = app(WebsiteMonitoringService::class)->monitor($site);
    expect($result['previous_status'])->toBe($previous)
        ->and($result['event_created'])->toBe($severity !== null)
        ->and($result['http_status'])->toBe($code)
        ->and($result['response_ms'])->toBeInt()->toBeGreaterThanOrEqual(0)
        ->and($site->fresh()->status)->toBe($code < 400 ? 'online' : 'offline')
        ->and($site->fresh()->last_http_status)->toBe($code)
        ->and($site->fresh()->last_response_ms)->toBe($result['response_ms'])
        ->and($site->fresh()->last_checked_at->equalTo(now()))->toBeTrue()
        ->and(Event::count())->toBe($severity !== null ? 1 : 0);
    if ($severity !== null) {
        $event = Event::sole();
        expect($event->type)->toBe('website')->and($event->source_id)->toBe($site->id)
            ->and($event->severity)->toBe($severity)->and($event->resolved_at)->toBeNull()
            ->and($event->occurred_at->equalTo($site->last_checked_at))->toBeTrue()
            ->and($event->message)->toContain($site->name, $site->url, "HTTP {$code}", "{$result['response_ms']} ms");
    }
    Http::assertSentCount(1);
})->with([
    ['unknown', 200, null, null],
    ['unknown', 404, 'warning', 'down'],
    ['online', 500, 'warning', 'down'],
    ['offline', 500, null, null],
    ['offline', 200, 'info', 'recovery'],
    ['online', 200, null, null],
    [null, 200, null, null],
    [null, 404, 'warning', 'down'],
]);

test('website sequence creates only one down and one recovery', function () {
    Http::fake(['https://example.test' => Http::sequence()->push('', 404)->push('', 500)->push('', 200)->push('', 200)]);
    $site = Website::create(['name' => 'Sequence', 'url' => 'https://example.test']);
    $notifier = $this->mock(MaxNotifier::class);
    $notifier->shouldReceive('sendWebsiteDown')->once();
    $notifier->shouldReceive('sendWebsiteRecovery')->once();
    $service = app(WebsiteMonitoringService::class);
    foreach ([true, false, true, false] as $changed) {
        expect($service->monitor($site)['event_created'])->toBe($changed);
    }
    expect(Event::orderBy('id')->pluck('severity')->all())->toBe(['warning', 'info'])
        ->and(Event::first()->message)->toContain('HTTP 404')
        ->and($site->fresh()->status)->toBe('online');
});

test('website transport down clears HTTP status and records a neutral failure snapshot', function () {
    Http::fake(fn () => throw new ConnectionException('Simulated failure'));
    $site = Website::create(['name' => 'Transport', 'url' => 'https://example.test', 'status' => 'online', 'last_http_status' => 200]);
    $this->mock(MaxNotifier::class)->shouldReceive('sendWebsiteDown')->once()
        ->with(Mockery::on(fn ($value) => $value->last_http_status === null));
    $result = app(WebsiteMonitoringService::class)->monitor($site);
    expect($result['http_status'])->toBeNull()->and($site->fresh()->last_http_status)->toBeNull()
        ->and(Event::sole()->message)->toContain('HTTP-ответ не получен')
        ->not->toContain('HTTP 200', 'DNS', 'TLS');
});

test('wordpress and maximum length names use website events safely', function (string $previous, int $code, string $method) {
    Http::fake(['https://example.test' => Http::response('', $code)]);
    $name = str_repeat('Я', 255);
    $site = Website::create(['name' => $name, 'url' => 'https://example.test', 'type' => 'wordpress', 'status' => $previous]);
    $this->mock(MaxNotifier::class)->shouldReceive($method)->once();
    app(WebsiteMonitoringService::class)->monitor($site);
    $event = Event::sole();
    expect(mb_strlen($event->title))->toBeLessThanOrEqual(255)
        ->and($event->type)->toBe('website')->and($event->source_id)->toBe($site->id)
        ->and($event->message)->toContain($name, $site->url);
})->with([['unknown', 500, 'sendWebsiteDown'], ['offline', 200, 'sendWebsiteRecovery']]);

test('website monitoring propagates programming errors', function () {
    Http::fake(fn () => throw new LogicException('Programming error'));
    $site = Website::create(['name' => 'Bug', 'url' => 'https://example.test']);
    $notifier = $this->mock(MaxNotifier::class);
    $notifier->shouldNotReceive('sendWebsiteDown');
    $notifier->shouldNotReceive('sendWebsiteRecovery');
    expect(fn () => app(WebsiteMonitoringService::class)->monitor($site))->toThrow(LogicException::class);
    expect(Event::count())->toBe(0)->and($site->fresh()->status)->toBe('unknown');
});

test('monitor websites processes enabled sites in ID order and reports normal offline outcomes', function () {
    $visited = [];
    Http::fake(function ($request) use (&$visited) {
        $visited[] = $request->url();
        if (str_ends_with($request->url(), '/transport')) {
            throw new ConnectionException('Simulated transport failure');
        }
        return Http::response('', str_ends_with($request->url(), '/online') ? 200 : 404);
    });
    foreach (['online', 'http', 'transport'] as $path) {
        Website::create(['name' => $path, 'url' => 'https://example.test/'.$path]);
    }
    $disabled = Website::create(['name' => 'Disabled', 'url' => 'https://example.test/disabled', 'enabled' => false, 'status' => 'offline', 'last_http_status' => 500]);
    $notifier = $this->mock(MaxNotifier::class);
    $notifier->shouldReceive('sendWebsiteDown')->twice();
    $notifier->shouldNotReceive('sendWebsiteRecovery');
    $this->artisan('monitor:websites')
        ->expectsOutput('Summary: checked=3  online=1  offline=2  changed=2  errors=0')->assertSuccessful();
    expect($visited)->toBe(['https://example.test/online', 'https://example.test/http', 'https://example.test/transport'])
        ->and(Event::count())->toBe(2)->and($disabled->fresh()->last_checked_at)->toBeNull()
        ->and($disabled->fresh()->last_http_status)->toBe(500)->and($disabled->fresh()->status)->toBe('offline');
});

test('monitor websites handles an empty list', function () {
    Http::fake();
    $this->artisan('monitor:websites')
        ->expectsOutput('Summary: checked=0  online=0  offline=0  changed=0  errors=0')->assertSuccessful();
    Http::assertNothingSent();
    expect(Event::count())->toBe(0);
});

test('monitor websites continues after the first site throws', function () {
    Exceptions::fake();
    $exception = new LogicException('Simulated bug');
    Http::fake([
        'https://example.test/first' => fn () => throw $exception,
        'https://example.test/second' => Http::response('', 200),
    ]);
    $first = Website::create(['name' => 'First', 'url' => 'https://example.test/first']);
    $second = Website::create(['name' => 'Second', 'url' => 'https://example.test/second']);
    $this->artisan('monitor:websites')
        ->expectsOutput('[ERROR] First: Simulated bug')
        ->expectsOutput('Summary: checked=1  online=1  offline=0  changed=0  errors=1')->assertSuccessful();
    expect($first->fresh()->status)->toBe('unknown')->and($second->fresh()->status)->toBe('online');
    Exceptions::assertReported(fn (LogicException $reported) => $reported === $exception);
    Exceptions::assertReportedCount(1);
});
