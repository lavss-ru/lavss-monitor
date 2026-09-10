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

test('website monitoring propagates programming errors', function () {
    Http::fake(fn () => throw new LogicException('Programming error'));
    $site = Website::create(['name' => 'Bug', 'url' => 'https://example.test']);
    $notifier = $this->mock(MaxNotifier::class);
    $notifier->shouldNotReceive('sendWebsiteAggregate');
    $notifier->shouldNotReceive('sendWebsiteAggregateRecovery');
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
    $notifier->shouldNotReceive('sendWebsiteAggregate');
    $notifier->shouldNotReceive('sendWebsiteAggregateRecovery');
    $this->artisan('monitor:websites')
        ->expectsOutput('Summary: checked=3  online=1  offline=2  changed=0  errors=0')->assertSuccessful();
    expect($visited)->toBe(['https://example.test/online', 'https://example.test/http', 'https://example.test/transport'])
        ->and(Event::count())->toBe(0)->and($disabled->fresh()->last_checked_at)->toBeNull()
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
