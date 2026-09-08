<?php

use App\Models\Website;
use App\Services\WebsiteHealthCheckService;
use GuzzleHttp\Exception\TooManyRedirectsException;
use GuzzleHttp\Psr7\Request;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => Http::preventStrayRequests());

test('website HTTP results are measured and persisted', function (int $code, string $status) {
    Http::fake(['https://example.com' => Http::response('', $code)]);
    $site = Website::create(['name' => 'Test', 'url' => 'https://example.com']);
    $result = app(WebsiteHealthCheckService::class)->check($site);
    expect($result['status'])->toBe($status)
        ->and($result['http_status'])->toBe($code)
        ->and($result['response_ms'])->toBeInt()->toBeGreaterThanOrEqual(0);
    $site->refresh();
    expect($site->status)->toBe($status)->and($site->last_http_status)->toBe($code)
        ->and($site->last_response_ms)->toBe($result['response_ms'])
        ->and($site->last_checked_at)->not->toBeNull();
    Http::assertSent(fn ($request) => $request->method() === 'GET');
})->with([[200, 'online'], [302, 'online'], [399, 'online'], [404, 'offline'], [500, 'offline']]);

test('website transport and redirect failures persist an offline result', function (string $failure) {
    Http::fake(function () use ($failure) {
        if ($failure === 'redirect') {
            throw new TooManyRedirectsException('Redirect limit', new Request('GET', 'https://example.com'));
        }
        throw new ConnectionException('Simulated DNS/connect/timeout/TLS failure');
    });
    $site = Website::create(['name' => 'Test', 'url' => 'https://example.com', 'status' => 'online', 'last_http_status' => 200]);
    $result = app(WebsiteHealthCheckService::class)->check($site);
    expect($result['status'])->toBe('offline')->and($result['http_status'])->toBeNull()
        ->and($result['response_ms'])->toBeInt()->toBeGreaterThanOrEqual(0);
    expect($site->refresh()->last_http_status)->toBeNull()->and($site->last_checked_at)->not->toBeNull();
})->with(['connection', 'redirect']);

test('website service rejects unsupported schemes before sending a request', function (string $url) {
    Http::fake();
    $site = Website::create(['name' => 'Invalid', 'url' => $url]);
    expect(fn () => app(WebsiteHealthCheckService::class)->check($site))->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
})->with(['ftp://example.com/file', 'file:///etc/passwd', 'javascript:alert(1)', 'example.com']);

test('website service does not swallow programming errors', function () {
    Http::fake(fn () => throw new LogicException('Bug'));
    $site = Website::create(['name' => 'Test', 'url' => 'https://example.com']);
    expect(fn () => app(WebsiteHealthCheckService::class)->check($site))->toThrow(LogicException::class);
});

test('website follows redirects using a mock transport with TLS verification enabled', function () {
    $history = [];
    $transport = new \GuzzleHttp\Handler\MockHandler([
        new \GuzzleHttp\Psr7\Response(302, ['Location' => 'https://example.com/final']),
        new \GuzzleHttp\Psr7\Response(200),
    ]);
    $stack = \GuzzleHttp\HandlerStack::create($transport);
    $stack->push(\GuzzleHttp\Middleware::history($history));
    // Every request terminates at MockHandler; there is no network-capable handler.
    Http::globalOptions(['handler' => $stack]);
    $site = Website::create(['name' => 'Redirect', 'url' => 'http://example.com/start']);
    $result = app(WebsiteHealthCheckService::class)->check($site);
    expect($result['status'])->toBe('online')->and($result['http_status'])->toBe(200)
        ->and($history)->toHaveCount(2);
    expect((string) $history[1]['request']->getUri())->toBe('https://example.com/final');
    foreach ($history as $request) {
        expect($request['options']['verify'])->toBeTrue()
            ->and($request['options']['timeout'])->toEqual(10)
            ->and($request['request']->getMethod())->toBe('GET');
    }
});


test('website converts only the transport hostname and preserves its display URL', function (string $url, string $transport) {
    Http::fake([$transport => Http::response('', 200)]);
    $site = Website::create(['name' => 'IDN', 'url' => $url]);
    app(WebsiteHealthCheckService::class)->check($site);
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->url() === $transport && $request->method() === 'GET');
    expect($site->refresh()->url)->toBe($url)->and($site->status)->toBe('online');
})->with([
    ['https://учцентр-оскол.рф', 'https://xn----itbqehhbmlgi0bm.xn--p1ai'],
    ['https://учцентр-оскол.рф/test/path', 'https://xn----itbqehhbmlgi0bm.xn--p1ai/test/path'],
    ['https://учцентр-оскол.рф?q=1&x=2', 'https://xn----itbqehhbmlgi0bm.xn--p1ai?q=1&x=2'],
    ['https://учцентр-оскол.рф:8443', 'https://xn----itbqehhbmlgi0bm.xn--p1ai:8443'],
    ['https://учцентр-оскол.рф:8443/test/a%20b?q=a%2Fb&x=1#part%20one', 'https://xn----itbqehhbmlgi0bm.xn--p1ai:8443/test/a%20b?q=a%2Fb&x=1#part%20one'],
    ['https://dshi1-oskol.ru', 'https://dshi1-oskol.ru'],
    ['https://xn----itbqehhbmlgi0bm.xn--p1ai', 'https://xn----itbqehhbmlgi0bm.xn--p1ai'],
    ['http://127.0.0.1:8080/test', 'http://127.0.0.1:8080/test'],
    ['http://[::1]:8080/test', 'http://[::1]:8080/test'],
    ['https://user:p%40ss@учцентр-оскол.рф:8443/учцентр-оскол.рф?q=%2f%2F#end', 'https://user:p%40ss@xn----itbqehhbmlgi0bm.xn--p1ai:8443/%D1%83%D1%87%D1%86%D0%B5%D0%BD%D1%82%D1%80-%D0%BE%D1%81%D0%BA%D0%BE%D0%BB.%D1%80%D1%84?q=%2f%2F#end'],
]);

test('invalid IDNA is rejected without HTTP or measurement changes', function (string $hostname) {
    $ascii = idn_to_ascii($hostname, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46, $info);
    expect($ascii === false || ($info['errors'] ?? 0) !== 0)->toBeTrue();
    Http::fake();
    $site = Website::create(['name' => 'Invalid IDN', 'url' => 'https://'.$hostname, 'status' => 'online', 'last_http_status' => 200]);
    expect(fn () => app(WebsiteHealthCheckService::class)->check($site))->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
    expect($site->refresh()->status)->toBe('online')->and($site->last_http_status)->toBe(200)
        ->and($site->last_checked_at)->toBeNull();
})->with(['пример..рф', '-пример.рф', 'xn--.рф', str_repeat('я', 64).'.рф']);

test('malformed transport URLs fail before HTTP', function (string $url) {
    Http::fake();
    $site = Website::create(['name' => 'Invalid', 'url' => $url]);
    expect(fn () => app(WebsiteHealthCheckService::class)->check($site))->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
})->with(['https:///path', 'https://', 'https://example.com:99999', "https://exa\nmple.com"]);
