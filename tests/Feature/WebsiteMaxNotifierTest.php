<?php

use App\Models\Website;
use App\Services\MaxNotifier;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    config(['services.max.bot_token' => 'website-test-token', 'services.max.user_id' => '123',
        'services.max.api_url' => 'https://max.example.test']);
});

test('aggregate MAX preserves authorization URL and recovery text', function () {
    Http::fake(['https://max.example.test/*' => Http::response([], 200)]);
    $site = Website::create(['name' => 'Example', 'url' => 'https://example.test']);
    expect(app(MaxNotifier::class)->sendWebsiteAggregate(collect([$site]), false))->toBeTrue();
    Http::assertSent(fn ($r) => $r->method() === 'POST'
        && $r->url() === 'https://max.example.test/messages?user_id=123'
        && $r->hasHeader('Authorization', 'website-test-token')
        && str_contains($r['text'], 'Example — https://example.test'));
    expect(app(MaxNotifier::class)->sendWebsiteAggregateRecovery())->toBeTrue();
    Http::assertSent(fn ($r) => $r['text'] === "🟢 Работа сайтов восстановлена\n\nВсе контролируемые сайты доступны.");
    Http::assertSentCount(2);
});

test('aggregate MAX reports unsuccessful delivery', function (string $failure) {
    Http::fake(fn () => match ($failure) {
        'connection' => throw new ConnectionException('Simulated MAX failure'),
        'api' => Http::response(['success' => false], 200),
        default => Http::response([], 500),
    });
    expect(app(MaxNotifier::class)->sendWebsiteAggregateRecovery())->toBeFalse();
})->with(['connection', 'http', 'api']);

test('aggregate MAX with missing credentials does not publish', function (string $missing) {
    Http::fake();
    config(['services.max.'.$missing => '']);
    expect(app(MaxNotifier::class)->sendWebsiteAggregate(collect(), false))->toBeFalse()
        ->and(app(MaxNotifier::class)->sendWebsiteAggregateRecovery())->toBeFalse();
    Http::assertNothingSent();
})->with(['bot_token', 'user_id']);
