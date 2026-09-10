<?php

use App\Models\Event;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteAggregateState;
use App\Services\WebsiteAggregateService;
use App\Services\WebsiteMonitoringService;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->travelTo(now()->startOfSecond());
    Http::preventStrayRequests();
    config(['services.max.bot_token' => 'fake-token', 'services.max.user_id' => '123',
        'services.max.api_url' => 'https://max.example.test']);
    $this->codes = [];
    $this->messages = [];
    $this->delivery = true;
    $this->duringCheck = null;
    Http::fake(function ($request) {
        if (str_starts_with($request->url(), 'https://max.example.test/')) {
            $this->messages[] = $request['text'];
            if ($this->delivery instanceof Throwable) {
                throw $this->delivery;
            }
            return Http::response([], $this->delivery ? 200 : 503);
        }
        if ($this->duringCheck !== null) {
            ($this->duringCheck)();
        }
        $code = $this->codes[$request->url()] ?? 500;
        if ($code instanceof Throwable) {
            throw $code;
        }
        return Http::response('', $code);
    });
});

function incidentSite(string $name = 'A', array $extra = []): Website
{
    return Website::create(array_merge(['name' => $name, 'url' => 'https://example.test/'.$name], $extra));
}

function incidentBatch(): void
{
    test()->artisan('monitor:websites')->assertSuccessful();
}

test('transient failure has actual health and no events or notifications', function () {
    $site = incidentSite();
    incidentBatch();
    expect($site->fresh()->status)->toBe('offline')->and($site->fresh()->failure_started_at)->not->toBeNull();
    $this->travel(599)->seconds();
    incidentBatch();
    expect($site->fresh()->incident_confirmed_at)->toBeNull();
    $this->codes[$site->url] = 200;
    incidentBatch();
    expect($site->fresh()->status)->toBe('online')->and($site->fresh()->failure_started_at)->toBeNull()
        ->and(Event::count())->toBe(0)->and($this->messages)->toBe([]);
});

test('exact 600 second boundary confirms once and restart retains dedup', function () {
    $site = incidentSite();
    incidentBatch();
    $this->travel(599)->seconds();
    incidentBatch();
    expect(Event::count())->toBe(0)->and($this->messages)->toBe([]);
    $this->travel(1)->seconds();
    incidentBatch();
    expect(Event::count())->toBe(1)->and($this->messages)->toHaveCount(1)
        ->and($site->fresh()->incident_confirmed_at->equalTo(now()))->toBeTrue()
        ->and($site->fresh()->incident_notified_at->equalTo(now()))->toBeTrue();
    $event = Event::sole();
    expect($event->severity)->toBe('warning')->and($event->source_id)->toBe($site->id)
        ->and($event->type)->toBe('website')->and($event->resolved_at)->toBeNull()
        ->and($event->message)->toContain($site->url, 'HTTP 500');
    $this->travel(5)->minutes();
    app()->forgetInstance(WebsiteAggregateService::class);
    app()->forgetInstance(WebsiteMonitoringService::class);
    incidentBatch();
    app(WebsiteAggregateService::class)->evaluate();
    expect(Event::count())->toBe(1)->and($this->messages)->toHaveCount(1);
    $this->codes[$site->url] = 200;
    incidentBatch();
    incidentBatch();
    expect(Event::orderBy('id')->pluck('severity')->all())->toBe(['warning', 'info'])
        ->and($this->messages)->toHaveCount(2)
        ->and($this->messages[1])->toBe("🟢 Работа сайтов восстановлена\n\nВсе контролируемые сайты доступны.")
        ->and($site->fresh()->failure_started_at)->toBeNull()
        ->and($site->fresh()->incident_confirmed_at)->toBeNull()
        ->and($site->fresh()->incident_notified_at)->toBeNull()
        ->and(WebsiteAggregateState::find(1)->published_at)->toBeNull();
    $this->codes[$site->url] = 500;
    incidentBatch();
    expect($site->fresh()->failure_started_at->equalTo(now()))->toBeTrue()->and(Event::count())->toBe(2);
    $this->travel(10)->minutes();
    incidentBatch();
    expect(Event::count())->toBe(3)->and($this->messages)->toHaveCount(3);
});

test('two confirmed websites publish one aggregate then partial and full recovery once', function () {
    $a = incidentSite();
    $b = incidentSite('B');
    incidentBatch();
    $this->travel(10)->minutes();
    incidentBatch();
    expect(Event::count())->toBe(2)->and($this->messages)->toHaveCount(1)
        ->and($this->messages[0])->toContain($a->url, $b->url);
    $this->codes[$a->url] = 200;
    incidentBatch();
    incidentBatch();
    expect($this->messages)->toHaveCount(2)->and($this->messages[1])->toContain($b->url)->not->toContain($a->url);
    $this->codes[$b->url] = 200;
    incidentBatch();
    incidentBatch();
    expect($this->messages)->toHaveCount(3)->and(Event::count())->toBe(4);
});

test('confirmed A includes grace B but does not mark B confirmed or notified', function () {
    $a = incidentSite();
    incidentBatch();
    $this->travel(10)->minutes();
    $b = incidentSite('B');
    incidentBatch();
    expect($this->messages)->toHaveCount(1)->and($this->messages[0])->toContain($a->url, $b->url)
        ->and($a->fresh()->incident_notified_at)->not->toBeNull()
        ->and($b->fresh()->incident_confirmed_at)->toBeNull()->and($b->fresh()->incident_notified_at)->toBeNull();
    $this->travel(10)->minutes();
    incidentBatch();
    expect(Event::count())->toBe(2)->and($this->messages)->toHaveCount(1)
        ->and($b->fresh()->incident_confirmed_at)->not->toBeNull()->and($b->fresh()->incident_notified_at)->toBeNull();
});

test('failed MAX publication and recovery retry without duplicate events', function () {
    $a = incidentSite();
    incidentBatch();
    $this->travel(10)->minutes();
    $this->delivery = false;
    incidentBatch();
    expect(Event::count())->toBe(1)->and(WebsiteAggregateState::find(1)->published_at)->toBeNull()
        ->and($a->fresh()->incident_notified_at)->toBeNull()->and($this->messages)->toHaveCount(1);
    $this->delivery = true;
    incidentBatch();
    expect(Event::count())->toBe(1)->and($a->fresh()->incident_notified_at)->not->toBeNull();
    $this->delivery = false;
    $this->codes[$a->url] = 200;
    incidentBatch();
    expect(WebsiteAggregateState::find(1)->published_at)->not->toBeNull();
    $this->delivery = true;
    incidentBatch();
    incidentBatch();
    expect(Event::count())->toBe(2)->and($this->messages)->toHaveCount(4)
        ->and(WebsiteAggregateState::find(1)->published_at)->toBeNull();
});

test('unpublished incident recovery never sends all restored', function () {
    $a = incidentSite();
    $this->delivery = false;
    incidentBatch();
    $this->travel(10)->minutes();
    incidentBatch();
    $this->delivery = true;
    $this->codes[$a->url] = 200;
    incidentBatch();
    expect($this->messages)->toHaveCount(1)->and(Event::count())->toBe(2);
});

test('unknown and failed batch cannot announce full recovery', function () {
    Exceptions::fake();
    $a = incidentSite();
    incidentBatch();
    $this->travel(10)->minutes();
    incidentBatch();
    $b = incidentSite('B');
    $this->codes[$a->url] = 200;
    $this->codes[$b->url] = new LogicException('Test failure');
    incidentBatch();
    app(WebsiteAggregateService::class)->evaluate();
    expect($this->messages)->toHaveCount(1)->and(WebsiteAggregateState::find(1)->published_at)->not->toBeNull();
    $b->update(['status' => 'online', 'last_checked_at' => now()]);
    incidentBatch();
    app(WebsiteAggregateService::class)->evaluate();
    expect($this->messages)->toHaveCount(1)->and($b->fresh()->status)->toBe('unknown');
    $this->codes[$b->url] = 200;
    incidentBatch();
    expect($this->messages)->toHaveCount(2);
});

test('URL and enabled changes reset incident fields without sending MAX', function (string $change) {
    $a = incidentSite();
    incidentBatch();
    $this->travel(10)->minutes();
    incidentBatch();
    $payload = ['name' => $a->name, 'url' => $a->url, 'type' => 'website', 'enabled' => true];
    $payload[$change] = $change === 'url' ? 'https://example.test/new' : false;
    $this->actingAs(User::factory()->create())->put('/websites/'.$a->id, $payload)->assertRedirect();
    expect($a->fresh()->failure_started_at)->toBeNull()->and($a->fresh()->incident_confirmed_at)->toBeNull()
        ->and($a->fresh()->incident_notified_at)->toBeNull()->and($this->messages)->toHaveCount(1);
    if ($change === 'enabled') {
        $this->post('/websites/'.$a->id.'/check')->assertRedirect();
        expect($a->fresh()->failure_started_at)->toBeNull()->and(Event::count())->toBe(1);
        $payload['enabled'] = true;
        $this->put('/websites/'.$a->id, $payload)->assertRedirect();
    } else {
        expect($a->fresh()->status)->toBe('unknown')->and($a->fresh()->last_checked_at)->toBeNull();
    }
    incidentBatch();
    expect($a->fresh()->failure_started_at->equalTo(now()))->toBeTrue()
        ->and($a->fresh()->incident_confirmed_at)->toBeNull()->and(Event::count())->toBe(1);
})->with(['url', 'enabled']);

test('manual check all checks whole batch before one MAX attempt', function () {
    $a = incidentSite();
    $b = incidentSite('B');
    $this->actingAs(User::factory()->create())->post('/websites/check-all')->assertRedirect();
    $this->travel(10)->minutes();
    $this->delivery = false;
    $this->post('/websites/check-all')->assertRedirect();
    expect(Event::count())->toBe(2)->and($this->messages)->toHaveCount(1)
        ->and($this->messages[0])->toContain($a->url, $b->url);
});

test('stale model checks reload persisted incident state before processing', function () {
    $a = incidentSite();
    $stale = Website::find($a->id);
    app(WebsiteMonitoringService::class)->monitor($a);
    $this->travel(10)->minutes();
    app(WebsiteMonitoringService::class)->monitor($a);
    app(WebsiteAggregateService::class)->evaluate();
    app(WebsiteMonitoringService::class)->monitor($stale);
    app(WebsiteAggregateService::class)->evaluate();
    expect(Event::count())->toBe(1)->and($this->messages)->toHaveCount(1);
});

test('removing all monitored sites closes aggregate without false recovery', function () {
    $a = incidentSite();
    incidentBatch();
    $this->travel(10)->minutes();
    incidentBatch();
    $this->actingAs(User::factory()->create())->delete('/websites/'.$a->id)->assertRedirect();
    incidentBatch();
    expect($this->messages)->toHaveCount(1)->and(WebsiteAggregateState::find(1)->published_at)->toBeNull();
});

test('failed partial update keeps last successful fingerprint for a later retry', function () {
    $a = incidentSite();
    $b = incidentSite('B');
    incidentBatch();
    $this->travel(10)->minutes();
    incidentBatch();
    $fingerprint = WebsiteAggregateState::find(1)->fingerprint;
    $this->codes[$a->url] = 200;
    $this->delivery = false;
    incidentBatch();
    expect(WebsiteAggregateState::find(1)->fingerprint)->toBe($fingerprint);
    $this->delivery = true;
    incidentBatch();
    incidentBatch();
    expect($this->messages)->toHaveCount(3)
        ->and($this->messages[1])->toBe($this->messages[2])
        ->and(WebsiteAggregateState::find(1)->fingerprint)->not->toBe($fingerprint);
});

test('transport failures confirm with neutral event text and safe long wordpress titles', function () {
    $site = incidentSite('Transport', ['name' => str_repeat('Я', 255), 'type' => 'wordpress', 'last_http_status' => 200]);
    $this->codes[$site->url] = new \Illuminate\Http\Client\ConnectionException('Fake transport error');
    incidentBatch();
    $this->travel(10)->minutes();
    incidentBatch();
    $event = Event::sole();
    expect(mb_strlen($event->title))->toBeLessThanOrEqual(255)
        ->and($event->message)->toContain($site->name, $site->url, 'HTTP-ответ не получен')
        ->not->toContain('HTTP 200', 'DNS', 'TLS')
        ->and($event->type)->toBe('website')->and($event->source_id)->toBe($site->id)
        ->and($site->fresh()->last_http_status)->toBeNull();
    $this->codes[$site->url] = 200;
    incidentBatch();
    expect(Event::count())->toBe(2)->and(mb_strlen(Event::orderByDesc('id')->first()->title))->toBeLessThanOrEqual(255);
});

test('MAX connection exception permits next batch retry and does not interrupt checks', function () {
    $a = incidentSite();
    $b = incidentSite('B');
    incidentBatch();
    $this->travel(10)->minutes();
    $this->delivery = new \Illuminate\Http\Client\ConnectionException('Fake MAX error');
    incidentBatch();
    expect(Event::count())->toBe(2)->and($a->fresh()->status)->toBe('offline')->and($b->fresh()->status)->toBe('offline')
        ->and(WebsiteAggregateState::find(1)->published_at)->toBeNull();
    $this->delivery = true;
    incidentBatch();
    expect(WebsiteAggregateState::find(1)->published_at)->not->toBeNull();
});


test('batch recovery requires coverage of websites enabled during the batch', function (bool $manual) {
    $a = incidentSite();
    $b = incidentSite('B', ['enabled' => false, 'status' => 'online', 'last_checked_at' => now()->subHour()]);
    incidentBatch();
    $this->travel(10)->minutes();
    incidentBatch();
    $this->codes[$a->url] = 200;
    $this->codes[$b->url] = 200;
    $this->duringCheck = fn () => $b->update(['enabled' => true]);
    if ($manual) {
        $this->actingAs(User::factory()->create())->post('/websites/check-all')->assertRedirect();
    } else {
        incidentBatch();
    }
    expect($this->messages)->toHaveCount(1)->and(WebsiteAggregateState::find(1)->published_at)->not->toBeNull();
    $this->duringCheck = null;
    incidentBatch();
    expect($this->messages)->toHaveCount(2);
})->with([false, true]);
