<?php

use App\Models\User;
use App\Models\Vps;
use App\Services\VpsHealthCheckService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ─── 1. Guest cannot access /vps ─────────────────────────────────────────────

test('guest cannot open vps index', function () {
    $response = $this->get('/vps');

    $response->assertRedirect('/login');
});

// ─── 2. Authenticated user can open /vps ─────────────────────────────────────

test('authenticated user can open vps index', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/vps');

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page->component('Vps/Index'));
});

// ─── 3. Authenticated user can create a VPS ──────────────────────────────────

test('authenticated user can create a vps', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/vps', [
        'name'       => 'Test-VPS-01',
        'ip_address' => '10.0.0.1',
        'check_port' => 22,
        'enabled'    => true,
    ]);

    $response->assertRedirect('/vps');
    $this->assertDatabaseHas('vps', [
        'name'       => 'Test-VPS-01',
        'ip_address' => '10.0.0.1',
        'status'     => 'unknown',
    ]);
});

// ─── 4. Validation rejects invalid IP ────────────────────────────────────────

test('validation rejects invalid ip address', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/vps', [
        'name'       => 'Bad-VPS',
        'ip_address' => 'not-an-ip',
        'check_port' => 22,
        'enabled'    => true,
    ]);

    $response->assertSessionHasErrors('ip_address');
    $this->assertDatabaseMissing('vps', ['name' => 'Bad-VPS']);
});

// ─── 5. Validation rejects invalid check_port ────────────────────────────────

test('validation rejects invalid check port', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/vps', [
        'name'       => 'Bad-Port-VPS',
        'ip_address' => '10.0.0.2',
        'check_port' => 99999,
        'enabled'    => true,
    ]);

    $response->assertSessionHasErrors('check_port');
});

// ─── 6. Manual health-check updates VPS ──────────────────────────────────────

test('manual health check updates vps status and timestamps', function () {
    $user = User::factory()->create();

    /** @var Vps $vps */
    $vps = Vps::create([
        'name'       => 'Mock-VPS',
        'ip_address' => '127.0.0.1',
        'check_port' => 22,
        'status'     => 'unknown',
        'enabled'    => true,
    ]);

    // Mock the service so no real TCP connection is made
    $mock = $this->mock(VpsHealthCheckService::class);
    $mock->shouldReceive('check')
        ->once()
        ->with(\Mockery::on(fn ($arg) => $arg->id === $vps->id))
        ->andReturnUsing(function (Vps $v) {
            $v->update([
                'status'           => 'online',
                'last_checked_at'  => now(),
                'last_response_ms' => 15,
            ]);
            return ['status' => 'online', 'response_ms' => 15];
        });

    $response = $this->actingAs($user)->post("/vps/{$vps->id}/check");

    $response->assertRedirect('/vps');
    $this->assertDatabaseHas('vps', [
        'id'          => $vps->id,
        'status'      => 'online',
        'last_response_ms' => 15,
    ]);
});

// ─── 7. check-all checks enabled VPS ─────────────────────────────────────────

test('check all checks all enabled vps servers', function () {
    $user = User::factory()->create();

    $vps1 = Vps::create(['name' => 'VPS-A', 'ip_address' => '1.2.3.4', 'check_port' => 22, 'enabled' => true]);
    $vps2 = Vps::create(['name' => 'VPS-B', 'ip_address' => '1.2.3.5', 'check_port' => 22, 'enabled' => true]);

    $mock = $this->mock(VpsHealthCheckService::class);
    $mock->shouldReceive('check')->twice()->andReturnUsing(function (Vps $v) {
        $v->update(['status' => 'online', 'last_checked_at' => now(), 'last_response_ms' => 10]);
        return ['status' => 'online', 'response_ms' => 10];
    });

    $response = $this->actingAs($user)->post('/vps/check-all');

    $response->assertRedirect('/vps');
});

// ─── 8. Disabled VPS is skipped in check-all ─────────────────────────────────

test('disabled vps is skipped in check all', function () {
    $user = User::factory()->create();

    $enabled  = Vps::create(['name' => 'Enabled-VPS',  'ip_address' => '1.2.3.4', 'check_port' => 22, 'enabled' => true]);
    $disabled = Vps::create(['name' => 'Disabled-VPS', 'ip_address' => '1.2.3.5', 'check_port' => 22, 'enabled' => false]);

    // The mock must be called exactly ONCE (for enabled VPS only)
    $mock = $this->mock(VpsHealthCheckService::class);
    $mock->shouldReceive('check')->once()
        ->with(\Mockery::on(fn ($arg) => $arg->id === $enabled->id))
        ->andReturnUsing(function (Vps $v) {
            $v->update(['status' => 'online', 'last_checked_at' => now(), 'last_response_ms' => 10]);
            return ['status' => 'online', 'response_ms' => 10];
        });

    $this->actingAs($user)->post('/vps/check-all');

    // Disabled VPS status must remain 'unknown'
    $this->assertDatabaseHas('vps', ['id' => $disabled->id, 'status' => 'unknown']);
});

// ─── 9. Offline VPS appears in dashboard attention items ─────────────────────

test('offline vps is included in dashboard attention items', function () {
    $user = User::factory()->create();

    Vps::create([
        'name'       => 'Offline-VPS',
        'ip_address' => '10.0.0.99',
        'check_port' => 22,
        'status'     => 'offline',
        'enabled'    => true,
    ]);

    $response = $this->actingAs($user)->get('/');

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('Dashboard')
        ->where('dashboard.overallStatus', 'warning')
        ->has('dashboard.attentionItems', 1)
        ->where('dashboard.attentionItems.0.category', 'VPS')
    );
});

// ─── 10. Dashboard VPS summary is calculated from real DB records ─────────────

test('dashboard vps summary reflects real database counts', function () {
    $user = User::factory()->create();

    // 2 online, 1 offline
    Vps::create(['name' => 'VPS-1', 'ip_address' => '10.0.0.1', 'check_port' => 22, 'status' => 'online',  'enabled' => true]);
    Vps::create(['name' => 'VPS-2', 'ip_address' => '10.0.0.2', 'check_port' => 22, 'status' => 'online',  'enabled' => true]);
    Vps::create(['name' => 'VPS-3', 'ip_address' => '10.0.0.3', 'check_port' => 22, 'status' => 'offline', 'enabled' => true]);

    $response = $this->actingAs($user)->get('/');

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('Dashboard')
        ->where('dashboard.summaries.vps.count', 3)
        ->where('dashboard.overallStatus', 'warning')
    );

    // unknown VPS should NOT count as offline
    $data = $response->original->getData()['page']['props']['dashboard'];
    expect($data['summaries']['vps']['count'])->toBe(3);
});

// ─── 11. Authenticated user can update a VPS ─────────────────────────────────

test('authenticated user can update a vps', function () {
    $user = User::factory()->create();

    $vps = Vps::create([
        'name'       => 'Old-Name',
        'ip_address' => '10.0.0.1',
        'check_port' => 22,
        'enabled'    => true,
    ]);

    $response = $this->actingAs($user)->put("/vps/{$vps->id}", [
        'name'        => 'New-Name',
        'ip_address'  => '10.0.0.2',
        'hostname'    => 'new.host.example.com',
        'description' => 'Updated description',
        'check_port'  => 80,
        'enabled'     => false,
    ]);

    $response->assertRedirect('/vps');
    $this->assertDatabaseHas('vps', [
        'id'          => $vps->id,
        'name'        => 'New-Name',
        'ip_address'  => '10.0.0.2',
        'hostname'    => 'new.host.example.com',
        'description' => 'Updated description',
        'check_port'  => 80,
        'enabled'     => false,
    ]);
});

// ─── 12. Authenticated user can delete a VPS ─────────────────────────────────

test('authenticated user can delete a vps', function () {
    $user = User::factory()->create();

    $vps = Vps::create([
        'name'       => 'To-Delete',
        'ip_address' => '10.0.0.5',
        'check_port' => 22,
        'enabled'    => true,
    ]);

    $response = $this->actingAs($user)->delete("/vps/{$vps->id}");

    $response->assertRedirect('/vps');
    $this->assertDatabaseMissing('vps', ['id' => $vps->id]);
});

// ─── 13. Guest cannot update a VPS ───────────────────────────────────────────

test('guest cannot update a vps', function () {
    $vps = Vps::create([
        'name'       => 'Protected-VPS',
        'ip_address' => '10.0.0.10',
        'check_port' => 22,
        'enabled'    => true,
    ]);

    $response = $this->put("/vps/{$vps->id}", [
        'name'       => 'Hacked',
        'ip_address' => '1.2.3.4',
        'check_port' => 22,
        'enabled'    => true,
    ]);

    $response->assertRedirect('/login');
    $this->assertDatabaseMissing('vps', ['name' => 'Hacked']);
});

// ─── 14. Guest cannot delete a VPS ───────────────────────────────────────────

test('guest cannot delete a vps', function () {
    $vps = Vps::create([
        'name'       => 'Cannot-Delete',
        'ip_address' => '10.0.0.11',
        'check_port' => 22,
        'enabled'    => true,
    ]);

    $response = $this->delete("/vps/{$vps->id}");

    $response->assertRedirect('/login');
    $this->assertDatabaseHas('vps', ['id' => $vps->id]);
});

// ─── 15. Update validates invalid IP ─────────────────────────────────────────

test('update validates invalid ip address', function () {
    $user = User::factory()->create();

    $vps = Vps::create([
        'name'       => 'Valid-VPS',
        'ip_address' => '10.0.0.20',
        'check_port' => 22,
        'enabled'    => true,
    ]);

    $response = $this->actingAs($user)->put("/vps/{$vps->id}", [
        'name'       => 'Valid-VPS',
        'ip_address' => 'not-an-ip',
        'check_port' => 22,
        'enabled'    => true,
    ]);

    $response->assertSessionHasErrors('ip_address');
    $this->assertDatabaseHas('vps', ['id' => $vps->id, 'ip_address' => '10.0.0.20']);
});

// ─── 16. Update validates invalid check_port ─────────────────────────────────

test('update validates invalid check port', function () {
    $user = User::factory()->create();

    $vps = Vps::create([
        'name'       => 'Port-VPS',
        'ip_address' => '10.0.0.21',
        'check_port' => 22,
        'enabled'    => true,
    ]);

    $response = $this->actingAs($user)->put("/vps/{$vps->id}", [
        'name'       => 'Port-VPS',
        'ip_address' => '10.0.0.21',
        'check_port' => 99999,
        'enabled'    => true,
    ]);

    $response->assertSessionHasErrors('check_port');
    $this->assertDatabaseHas('vps', ['id' => $vps->id, 'check_port' => 22]);
});
