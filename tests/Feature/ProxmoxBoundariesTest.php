<?php

use App\Models\User;
use App\Services\ProxmoxSyncService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/../Support/Proxmox.php';

test('proxmox fetch does not open a database transaction', function () {
    $c = pveConnection();
    $level = DB::transactionLevel(); // RefreshDatabase itself may own the outer test transaction.
    Http::fake(function ($request) use ($level) {
        expect(DB::transactionLevel())->toBe($level);

        return Http::response(['data' => str_ends_with($request->url(), '/nodes')
            ? [['node' => 'pve1'], ['node' => 'pve2']] : pveResources()]);
    });
    expect(app(ProxmoxSyncService::class)->run($c)['status'])->toBe('online');
});

test('proxmox page query count does not grow with inventory size', function () {
    $this->actingAs(User::factory()->create());
    $c = pveConnection();
    pveFake();
    app(ProxmoxSyncService::class)->run($c);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $this->get('/proxmox')->assertOk();
    $small = count(DB::getQueryLog());
    foreach (range(200, 249) as $id) {
        $node = $c->nodes()->create(['node_name' => 'node-'.$id]);
        $c->guests()->create(['proxmox_node_id' => $node->id, 'guest_type' => 'qemu', 'vmid' => $id]);
    }
    DB::flushQueryLog();
    $this->get('/proxmox')->assertOk();
    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual($small);
    DB::disableQueryLog();
});

test('proxmox host edit invalidates previous inventory until next sync', function () {
    $this->actingAs(User::factory()->create());
    $c = pveConnection();
    pveFake();
    app(ProxmoxSyncService::class)->run($c);
    $this->put('/proxmox/'.$c->id, pvePayload($c, ['host' => 'new.example.test']))->assertSessionHasNoErrors();
    expect($c->fresh()->status)->toBe('unknown')->and($c->guests()->where('stale', true)->count())->toBe(2)
        ->and($c->nodes()->where('stale', true)->count())->toBe(2);
});

test('proxmox invalid secret validation response contains no supplied value', function () {
    $this->actingAs(User::factory()->create());
    $c = pveConnection();
    $response = $this->postJson('/proxmox', pvePayload($c, ['api_token_secret' => "synthetic-test-secret\r\nX-Header: value"]));
    $response->assertUnprocessable()->assertJsonValidationErrors('api_token_secret');
    expect($response->getContent())->not->toContain('synthetic-test-secret', 'X-Header');
});
