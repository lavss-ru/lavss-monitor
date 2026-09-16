<?php

use App\Models\ProxmoxConnection;
use App\Models\User;
use App\Services\ProxmoxSyncService;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

require_once __DIR__.'/../Support/Proxmox.php';

test('proxmox encrypts secret at rest and hides serialization', function () {
    $c = pveConnection();
    $raw = DB::table('proxmox_connections')->where('id', $c->id)->value('api_token_secret');
    expect($raw)->not->toContain('synthetic-test-secret')->and(Crypt::decryptString($raw))->toBe('synthetic-test-secret')
        ->and($c->api_token_secret)->toBe('synthetic-test-secret')
        ->and($c->toArray())->not->toHaveKey('api_token_secret')->and($c->toJson())->not->toContain('synthetic-test-secret');
});

test('all proxmox routes require authentication', function (string $method, string $suffix) {
    $c = pveConnection();
    pveFake();
    $this->{$method}('/proxmox'.str_replace('{id}', (string) $c->id, $suffix))->assertRedirect('/login');
    Http::assertNothingSent();
})->with([['get', ''], ['post', ''], ['put', '/{id}'], ['delete', '/{id}'], ['post', '/{id}/test'], ['post', '/{id}/sync']]);

test('proxmox create update replacement blank secret and delete are local only', function () {
    $this->actingAs(User::factory()->create());
    pveFake();
    $prototype = pveConnection();
    $payload = pvePayload($prototype, ['api_token_secret' => 'synthetic-replacement']);
    $this->post('/proxmox', $payload)->assertRedirect('/proxmox')->assertSessionHasNoErrors();
    $c = ProxmoxConnection::latest('id')->first();
    expect($c->api_token_secret)->toBe('synthetic-replacement')->and($c->location_id)->toBe($prototype->location_id);
    $this->put('/proxmox/'.$c->id, pvePayload($c, ['name' => 'Renamed']))->assertSessionHasNoErrors();
    expect($c->fresh()->api_token_secret)->toBe('synthetic-replacement')->and($c->fresh()->name)->toBe('Renamed');
    $this->put('/proxmox/'.$c->id, pvePayload($c, ['api_token_secret' => 'synthetic-new']))->assertSessionHasNoErrors();
    expect($c->fresh()->api_token_secret)->toBe('synthetic-new');
    Http::assertNothingSent();
    app(ProxmoxSyncService::class)->run($c);
    $this->delete('/proxmox/'.$c->id)->assertRedirect('/proxmox');
    $this->assertDatabaseMissing('proxmox_connections', ['id' => $c->id]);
    $this->assertDatabaseCount('proxmox_nodes', 0);
    $this->assertDatabaseCount('proxmox_guests', 0);
    Http::assertSentCount(2);
});

test('proxmox validation never flashes secret on any field failure', function (string $field, mixed $value) {
    $this->actingAs(User::factory()->create());
    $c = pveConnection();
    pveFake();
    $payload = pvePayload($c, ['api_token_secret' => 'synthetic-test-secret', $field => $value]);
    $response = $this->from('/proxmox')->post('/proxmox', $payload);
    $response->assertSessionHasErrors($field)->assertSessionMissing('_old_input.api_token_secret');
    expect(json_encode(session()->all()))->not->toContain('synthetic-test-secret', 'PVEAPIToken');
    Http::assertNothingSent();
})->with([['api_token_secret', ''], ['host', 'https://pve.test'], ['host', 'bad/path'], ['host', '192.999.1.1'],
    ['port', 0], ['port', 65536], ['location_id', 99999], ['api_user', "bad\r\nheader"],
    ['api_token_id', 'bad!token'], ['scheme', 'ftp'], ['verify_tls', 'maybe']]);

test('proxmox page never includes secret and never fetches API', function () {
    $c = pveConnection();
    pveFake();
    $this->actingAs(User::factory()->create())->get('/proxmox')->assertInertia(fn ($page) => $page
        ->component('Proxmox/Index')->has('connections', 1)->where('connections.0.location.id', $c->location_id)
        ->missing('connections.0.api_token_secret')->missing('connections.0.revision'));
    $response = $this->get('/proxmox');
    expect($response->getContent())->not->toContain('synthetic-test-secret', 'PVEAPIToken');
    Http::assertNothingSent();
});

test('proxmox actions show safe success and auth errors without logs or history', function () {
    $this->actingAs(User::factory()->create());
    $c = pveConnection();
    pveFake();
    $this->post('/proxmox/'.$c->id.'/test')->assertSessionHas('proxmox_result.success', true);
    expect($c->fresh()->status)->toBe('online')->and($c->guests()->count())->toBe(0);
    $this->post('/proxmox/'.$c->id.'/sync')->assertSessionHas('proxmox_result.success', true);
    expect($c->guests()->count())->toBe(2);
    localHttpFake(['*' => Http::response('synthetic-test-secret PVEAPIToken=', 403)]);
    Log::spy();
    $this->post('/proxmox/'.$c->id.'/test')->assertSessionHas('proxmox_result.message', 'Ошибка авторизации API');
    expect($c->fresh()->status)->toBe('unknown')->and($c->guests()->count())->toBe(2);
    foreach (['error', 'warning', 'info', 'debug'] as $level) {
        Log::shouldNotHaveReceived($level);
    }
    expect(json_encode(session()->all()))->not->toContain('synthetic-test-secret', 'PVEAPIToken');
    $this->assertDatabaseCount('events', 0);
    $this->assertDatabaseCount('monitor_checks', 0);
});

test('proxmox location deletion is protected and existing local device survives', function () {
    $this->actingAs(User::factory()->create());
    $c = pveConnection();
    $this->delete('/locations/'.$c->location_id)->assertSessionHasErrors('location');
    $device = localDevice(['location_id' => $c->location_id]);
    $this->delete('/proxmox/'.$c->id)->assertRedirect('/proxmox');
    $this->assertDatabaseHas('local_devices', ['id' => $device->id]);
});

test('proxmox derived location unknown applies before next scheduler cycle', function () {
    $c = pveConnection(['status' => 'online']);
    $c->location->update(['enabled' => false]);
    pveFake();
    $this->actingAs(User::factory()->create())->get('/proxmox')->assertInertia(fn ($page) => $page
        ->where('connections.0.status', 'unknown')->where('connections.0.unavailable_reason', 'location_unavailable'));
    expect($c->fresh()->status)->toBe('online');
    Http::assertNothingSent();
});

test('proxmox mutations retain CSRF middleware', function () {
    $routes = collect(app('router')->getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'proxmox'));
    expect($routes)->toHaveCount(6);
    foreach ($routes as $route) {
        $middleware = app('router')->gatherRouteMiddleware($route);
        expect($middleware)->toContain(PreventRequestForgery::class)
            ->toContain(Authenticate::class);
    }
});
