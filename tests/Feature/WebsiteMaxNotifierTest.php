<?php

use App\Models\Event;
use App\Models\Website;
use App\Services\MaxNotifier;
use App\Services\WebsiteMonitoringService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->travelTo(now()->startOfSecond());
    config([
        'services.max.bot_token' => 'website-test-token',
        'services.max.user_id' => '123',
        'services.max.api_url' => 'https://max.example.test',
    ]);
});

test('website MAX payload contains the measured snapshot and preserves authorization contract', function (?int $code, bool $recovery) {
    Http::fake(['https://max.example.test/*' => Http::response([], 200)]);
    $checkedAt = now()->subMinutes(2);
    $site = Website::create([
        'name' => 'Example', 'url' => 'https://example.test', 'last_http_status' => $code,
        'last_response_ms' => 125, 'last_checked_at' => $checkedAt,
    ]);
    $notifier = app(MaxNotifier::class);
    $recovery ? $notifier->sendWebsiteRecovery($site) : $notifier->sendWebsiteDown($site);
    $result = $code === null ? 'HTTP-ответ не получен' : "HTTP {$code}";
    $heading = $recovery ? '🟢 Сайт снова доступен' : '🔴 Сайт недоступен';
    $timeLabel = $recovery ? 'Время восстановления' : 'Время';
    $expected = implode("\n", [
        $heading, '', 'Сайт: Example', 'URL: https://example.test', "Результат: {$result}",
        'Длительность проверки: 125 ms', "{$timeLabel}: ".$checkedAt->format('d.m.Y H:i:s T'),
    ]);
    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && $request->url() === 'https://max.example.test/messages?user_id=123'
        && $request->hasHeader('Authorization', 'website-test-token')
        && $request['text'] === $expected);
    Http::assertSentCount(1);
})->with([[404, false], [500, false], [null, false], [200, true]]);

test('website MAX sends nothing with missing credentials', function (string $missing, string $method) {
    Http::fake();
    config(['services.max.'.$missing => '']);
    $site = Website::create(['name' => 'Example', 'url' => 'https://example.test']);
    app(MaxNotifier::class)->{$method}($site);
    Http::assertNothingSent();
})->with(['bot_token', 'user_id'])->with(['sendWebsiteDown', 'sendWebsiteRecovery']);

test('website monitoring survives MAX failures and does not resend a stable state', function (string $failure, bool $recovery) {
    $attempts = 0;
    Http::fake([
        'https://example.test' => Http::response('', $recovery ? 200 : 500),
        'https://max.example.test/*' => function () use ($failure, &$attempts) {
            $attempts++;
            if ($failure === 'connection') {
                throw new ConnectionException('Simulated MAX failure');
            }
            return Http::response([], 500);
        },
    ]);
    $site = Website::create(['name' => 'Example', 'url' => 'https://example.test', 'status' => $recovery ? 'offline' : 'online']);
    $service = app(WebsiteMonitoringService::class);
    expect($service->monitor($site)['event_created'])->toBeTrue()
        ->and($service->monitor($site)['event_created'])->toBeFalse()
        ->and($site->fresh()->status)->toBe($recovery ? 'online' : 'offline')
        ->and(Event::count())->toBe(1)->and($attempts)->toBe(1);
})->with(['http', 'connection'])->with([false, true]);

test('website command continues processing after a MAX connection failure', function () {
    Http::fake([
        'https://example.test/*' => Http::response('', 500),
        'https://max.example.test/*' => fn () => throw new ConnectionException('Simulated MAX failure'),
    ]);
    foreach (['first', 'second'] as $name) {
        Website::create(['name' => $name, 'url' => 'https://example.test/'.$name]);
    }
    $this->artisan('monitor:websites')
        ->expectsOutput('Summary: checked=2  online=0  offline=2  changed=2  errors=0')->assertSuccessful();
    expect(Event::count())->toBe(2)->and(Website::where('status', 'offline')->count())->toBe(2);
});
