<?php

use App\Models\Event;
use App\Models\User;
use App\Models\Website;
use App\Services\MaxNotifier;
use App\Services\WebsiteHealthCheckService;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => Http::preventStrayRequests());

function websitePayload(array $overrides = []): array
{
    return array_merge(['name' => 'Example', 'url' => 'https://example.com', 'type' => 'website', 'enabled' => true, 'description' => 'Description'], $overrides);
}

test('all website routes require authentication', function (string $method, string $path) {
    $site = Website::create(websitePayload());
    $this->{$method}(str_replace('{id}', (string) $site->id, $path), websitePayload())->assertRedirect('/login');
    expect(Website::count())->toBe(1)->and($site->refresh()->last_checked_at)->toBeNull();
})->with([['get', '/websites'], ['post', '/websites'], ['put', '/websites/{id}'], ['delete', '/websites/{id}'], ['post', '/websites/{id}/check'], ['post', '/websites/check-all']]);

test('website CRUD and shared count use real records and ignore monitoring payload', function () {
    $this->actingAs(User::factory()->create());
    $injected = ['status' => 'online', 'last_http_status' => 200, 'last_response_ms' => 1, 'last_checked_at' => now()->toISOString()];
    $this->post('/websites', websitePayload($injected))->assertRedirect('/websites');
    $site = Website::firstOrFail();
    expect($site->status)->toBe('unknown')->and($site->last_http_status)->toBeNull()->and($site->last_response_ms)->toBeNull()->and($site->last_checked_at)->toBeNull();
    $this->get('/websites')->assertInertia(fn ($page) => $page->component('Website/Index')->has('websiteList', 1)->where('websiteCount', 1)->where('vpsCount', 0));
    $this->put('/websites/'.$site->id, websitePayload(array_merge($injected, ['name' => 'Updated', 'type' => 'wordpress', 'enabled' => false])))->assertRedirect('/websites');
    expect($site->refresh()->name)->toBe('Updated')->and($site->type)->toBe('wordpress')->and($site->enabled)->toBeFalse()->and($site->status)->toBe('unknown')->and($site->last_http_status)->toBeNull()->and($site->last_response_ms)->toBeNull()->and($site->last_checked_at)->toBeNull();
    $this->delete('/websites/'.$site->id)->assertRedirect('/websites');
    expect(Website::count())->toBe(0);
});

test('website create and update validate input', function (string $field, mixed $value) {
    $this->actingAs(User::factory()->create());
    $site = Website::create(websitePayload());
    $this->post('/websites', websitePayload([$field => $value]))->assertSessionHasErrors($field);
    $this->put('/websites/'.$site->id, websitePayload([$field => $value]))->assertSessionHasErrors($field);
    expect(Website::count())->toBe(1)->and($site->refresh()->url)->toBe('https://example.com');
})->with([['name', ''], ['url', ''], ['url', 'ftp://example.com'], ['url', 'javascript:alert(1)'], ['url', 'https://example.com/'.str_repeat('a', 255)], ['type', 'vps'], ['enabled', 'yes']]);

test('website accepts HTTP and HTTPS URLs', function (string $url) {
    $this->actingAs(User::factory()->create())->post('/websites', websitePayload(['url' => $url]))->assertSessionHasNoErrors();
    $this->assertDatabaseHas('websites', ['url' => $url]);
})->with(['http://example.com', 'https://example.com']);

test('only a changed URL resets website monitoring fields', function (bool $changeUrl) {
    $site = Website::create(websitePayload(['status' => 'offline', 'last_http_status' => 500, 'last_response_ms' => 42, 'last_checked_at' => now()->subMinute()]));
    $checkedAt = $site->last_checked_at->toISOString();
    $this->actingAs(User::factory()->create())->put('/websites/'.$site->id, websitePayload(['name' => 'Renamed', 'url' => $changeUrl ? 'https://other.example.com' : $site->url]))->assertRedirect('/websites');
    $site->refresh();
    expect($site->status)->toBe($changeUrl ? 'unknown' : 'offline')
        ->and($site->last_http_status)->toBe($changeUrl ? null : 500)
        ->and($site->last_response_ms)->toBe($changeUrl ? null : 42)
        ->and($site->last_checked_at?->toISOString())->toBe($changeUrl ? null : $checkedAt);
})->with([true, false]);

test('manual website check updates all monitoring fields', function () {
    Http::fake(['https://example.com' => Http::response('', 404)]);
    $site = Website::create(websitePayload());
    $this->actingAs(User::factory()->create())->post('/websites/'.$site->id.'/check')->assertRedirect('/websites');
    expect($site->refresh()->status)->toBe('offline')->and($site->last_http_status)->toBe(404)
        ->and($site->last_response_ms)->toBeInt()->toBeGreaterThanOrEqual(0)->and($site->last_checked_at)->not->toBeNull();
});

test('check all checks enabled websites and skips disabled websites', function () {
    Http::fake(['https://example.com/*' => Http::response('', 200)]);
    $first = Website::create(websitePayload(['url' => 'https://example.com/first']));
    $second = Website::create(websitePayload(['url' => 'https://example.com/second']));
    $disabled = Website::create(websitePayload(['enabled' => false, 'url' => 'https://example.com/disabled']));
    $this->actingAs(User::factory()->create())->post('/websites/check-all')->assertRedirect('/websites');
    Http::assertSentCount(2);
    Http::assertNotSent(fn ($request) => $request->url() === $disabled->url);
    foreach ([$first, $second] as $site) {
        expect($site->refresh()->status)->toBe('online')->and($site->last_http_status)->toBe(200)->and($site->last_checked_at)->not->toBeNull()->and($site->last_response_ms)->toBeInt();
    }
    expect($disabled->refresh()->status)->toBe('unknown')->and($disabled->last_checked_at)->toBeNull();
});

test('a failed website does not stop later checks', function () {
    $first = Website::create(websitePayload());
    $second = Website::create(websitePayload(['url' => 'https://second.example.com']));
    $this->mock(WebsiteHealthCheckService::class, function ($mock) use ($first, $second) {
        $mock->shouldReceive('check')->once()->with(Mockery::on(fn ($site) => $site->id === $first->id))->andThrow(new RuntimeException('Simulated failure'));
        $mock->shouldReceive('check')->once()->with(Mockery::on(fn ($site) => $site->id === $second->id))->andReturnUsing(function ($site) {
            $site->update(['status' => 'online', 'last_http_status' => 200, 'last_response_ms' => 5, 'last_checked_at' => now()]);
            return ['status' => 'online', 'http_status' => 200, 'response_ms' => 5];
        });
    });
    $this->actingAs(User::factory()->create())->post('/websites/check-all')->assertRedirect('/websites');
    expect($second->refresh()->status)->toBe('online');
});

test('enabled manual checks share transitions with the automatic command', function (bool $all) {
    Http::fake(['https://example.com' => Http::sequence()->push('', 404)->push('', 500)->push('', 200)]);
    $site = Website::create(websitePayload(['status' => 'online']));
    $notifier = $this->mock(MaxNotifier::class);
    $notifier->shouldReceive('sendWebsiteDown')->once();
    $notifier->shouldReceive('sendWebsiteRecovery')->once();
    $path = $all ? '/websites/check-all' : '/websites/'.$site->id.'/check';
    $this->actingAs(User::factory()->create())->post($path)->assertRedirect('/websites');
    expect(Event::count())->toBe(1)->and(Event::sole()->severity)->toBe('warning');
    $this->artisan('monitor:websites')->assertSuccessful();
    expect(Event::count())->toBe(1)->and($site->fresh()->last_http_status)->toBe(500);
    $this->post($path)->assertRedirect('/websites');
    expect(Event::orderBy('id')->pluck('severity')->all())->toBe(['warning', 'info']);
})->with([false, true]);

test('disabled single diagnostics update measurements without events or MAX', function (string $previous, int $code) {
    Http::fake(['https://example.com' => Http::response('', $code)]);
    $site = Website::create(websitePayload(['enabled' => false, 'status' => $previous]));
    $notifier = $this->mock(MaxNotifier::class);
    $notifier->shouldNotReceive('sendWebsiteDown');
    $notifier->shouldNotReceive('sendWebsiteRecovery');
    $this->actingAs(User::factory()->create())->post('/websites/'.$site->id.'/check')->assertRedirect('/websites');
    expect($site->fresh()->status)->toBe($code < 400 ? 'online' : 'offline')
        ->and($site->fresh()->last_http_status)->toBe($code)
        ->and($site->fresh()->last_checked_at)->not->toBeNull()
        ->and($site->fresh()->last_response_ms)->toBeInt()
        ->and(Event::count())->toBe(0);
    $this->post('/websites/check-all')->assertRedirect('/websites');
    $this->artisan('monitor:websites')->assertSuccessful();
    Http::assertSentCount(1);
})->with([['online', 404], ['offline', 200]]);

test('disable and re-enable preserve measurements and the next transition baseline', function (int $nextCode) {
    $this->travelTo(now()->startOfSecond());
    Http::fake(['https://example.com' => Http::response('', $nextCode)]);
    $site = Website::create(websitePayload([
        'status' => 'offline', 'last_http_status' => 500, 'last_response_ms' => 42,
        'last_checked_at' => now()->subHour(),
    ]));
    $notifier = $this->mock(MaxNotifier::class);
    $notifier->shouldNotReceive('sendWebsiteDown');
    $notifier->shouldReceive('sendWebsiteRecovery')->times($nextCode === 200 ? 1 : 0);
    $this->actingAs(User::factory()->create());
    foreach ([false, true] as $enabled) {
        $this->put('/websites/'.$site->id, websitePayload(['enabled' => $enabled]))->assertRedirect('/websites');
        expect($site->refresh()->status)->toBe('offline')->and($site->last_http_status)->toBe(500)
            ->and($site->last_response_ms)->toBe(42)
            ->and($site->last_checked_at->equalTo(now()->subHour()))->toBeTrue()
            ->and(Event::count())->toBe(0);
    }
    Http::assertNothingSent();
    $this->artisan('monitor:websites')->assertSuccessful();
    expect(Event::count())->toBe($nextCode === 200 ? 1 : 0);
})->with([200, 500]);
