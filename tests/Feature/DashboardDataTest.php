<?php

use App\Models\Event;
use App\Models\Infrastructure;
use App\Models\User;
use App\Models\Vps;
use App\Models\Website;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('can create vps record', function () {
    $vps = Vps::create([
        'name' => 'VPS-Test',
        'hostname' => 'test.lavss.ru',
        'ip_address' => '1.2.3.4',
        'description' => 'Test VPS',
        'enabled' => true,
    ]);

    expect($vps)->toBeInstanceOf(Vps::class);
    $this->assertDatabaseHas('vps', [
        'name' => 'VPS-Test',
        'ip_address' => '1.2.3.4',
    ]);
});

test('can create website record', function () {
    $site = Website::create([
        'name' => 'example.com',
        'url' => 'https://example.com',
        'type' => 'wordpress',
        'description' => 'Test site',
        'enabled' => true,
    ]);

    expect($site)->toBeInstanceOf(Website::class);
    $this->assertDatabaseHas('websites', [
        'name' => 'example.com',
        'url' => 'https://example.com',
    ]);
});

test('can create infrastructure record', function () {
    $infra = Infrastructure::create([
        'name' => 'PVE Node Test',
        'type' => 'proxmox',
        'description' => 'Test node',
        'enabled' => true,
    ]);

    expect($infra)->toBeInstanceOf(Infrastructure::class);
    $this->assertDatabaseHas('infrastructures', [
        'name' => 'PVE Node Test',
        'type' => 'proxmox',
    ]);
});

test('dashboard receives data from database', function () {
    $user = User::factory()->create(['email' => 'lavss@lavss.ru']);

    Vps::create([
        'name' => 'VPS-DB-01',
        'ip_address' => '10.0.0.1',
        'enabled' => true,
    ]);

    Website::create([
        'name' => 'dbsite.ru',
        'url' => 'https://dbsite.ru',
        'type' => 'wordpress',
        'enabled' => true,
    ]);

    Infrastructure::create([
        'name' => 'PVE DB',
        'type' => 'proxmox',
        'enabled' => true,
    ]);

    $response = $this->actingAs($user)->get('/');

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('Dashboard')
        ->has('dashboard.vpsList', 1)
        ->where('dashboard.vpsList.0.name', 'VPS-DB-01')
        ->has('dashboard.websitesList', 1)
        ->where('dashboard.websitesList.0.name', 'dbsite.ru')
        ->has('dashboard.infrastructureList', 1)
        ->where('dashboard.infrastructureList.0.name', 'PVE DB')
    );
});

test('demo seeder does not create duplicates on repeated runs', function () {
    $this->seed(DemoSeeder::class);

    $vpsCountBefore = Vps::count();
    $websiteCountBefore = Website::count();
    $infraCountBefore = Infrastructure::count();
    $eventCountBefore = Event::count();

    // Run DemoSeeder a second time
    $this->seed(DemoSeeder::class);

    expect(Vps::count())->toBe($vpsCountBefore);
    expect(Website::count())->toBe($websiteCountBefore);
    expect(Infrastructure::count())->toBe($infraCountBefore);
    expect(Event::count())->toBe($eventCountBefore);
});

// ─── Attention items deduplication ───────────────────────────────────────────

test('offline vps with warning event produces exactly one attention item', function () {
    $user = User::factory()->create();

    $vps = Vps::create([
        'name'       => 'Dup-VPS',
        'ip_address' => '10.0.1.1',
        'check_port' => 22,
        'status'     => 'offline',
        'enabled'    => true,
    ]);

    // VPS warning event (unresolved) — should NOT add a second attention item
    Event::create([
        'type'        => 'vps',
        'severity'    => 'warning',
        'title'       => "VPS {$vps->name} недоступен",
        'message'     => 'TCP connect не удался.',
        'source_id'   => $vps->id,
        'occurred_at' => now(),
        'resolved_at' => null,
    ]);

    $response = $this->actingAs($user)->get('/');

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('Dashboard')
        ->has('dashboard.attentionItems', 1)          // exactly ONE item
        ->where('dashboard.attentionItems.0.category', 'VPS')
        ->has('dashboard.recentEvents', 1)            // event still in history
        ->where('dashboard.recentEvents.0.title', "VPS {$vps->name} недоступен")
    );
});

test('online vps does not appear in attention items', function () {
    $user = User::factory()->create();

    Vps::create([
        'name'       => 'Online-VPS',
        'ip_address' => '10.0.1.2',
        'check_port' => 22,
        'status'     => 'online',
        'enabled'    => true,
    ]);

    $response = $this->actingAs($user)->get('/');

    $response->assertInertia(fn ($page) => $page
        ->component('Dashboard')
        ->has('dashboard.attentionItems', 0)
    );
});

test('multiple offline vps each produce exactly one attention item', function () {
    $user = User::factory()->create();

    $vps1 = Vps::create(['name' => 'VPS-X1', 'ip_address' => '10.0.2.1', 'check_port' => 22, 'status' => 'offline', 'enabled' => true]);
    $vps2 = Vps::create(['name' => 'VPS-X2', 'ip_address' => '10.0.2.2', 'check_port' => 22, 'status' => 'offline', 'enabled' => true]);

    // Two VPS warning events — should not add extra items
    foreach ([$vps1, $vps2] as $vps) {
        Event::create([
            'type'        => 'vps',
            'severity'    => 'warning',
            'title'       => "VPS {$vps->name} недоступен",
            'message'     => 'TCP connect не удался.',
            'source_id'   => $vps->id,
            'occurred_at' => now(),
            'resolved_at' => null,
        ]);
    }

    $response = $this->actingAs($user)->get('/');

    $response->assertInertia(fn ($page) => $page
        ->component('Dashboard')
        ->has('dashboard.attentionItems', 2)   // exactly 2 — one per VPS
        ->has('dashboard.recentEvents', 2)     // both events in history
    );
});

test('vps event that is resolved does not appear in attention items', function () {
    $user = User::factory()->create();

    $vps = Vps::create([
        'name'       => 'Recovered-VPS',
        'ip_address' => '10.0.3.1',
        'check_port' => 22,
        'status'     => 'online',  // currently online — recovered
        'enabled'    => true,
    ]);

    // Old unresolved warning event (VPS type) — excluded from attention because VPS is online
    Event::create([
        'type'        => 'vps',
        'severity'    => 'warning',
        'title'       => "VPS {$vps->name} недоступен",
        'message'     => 'TCP connect не удался.',
        'source_id'   => $vps->id,
        'occurred_at' => now()->subMinutes(10),
        'resolved_at' => null,
    ]);

    // Recovery event in history
    Event::create([
        'type'        => 'vps',
        'severity'    => 'info',
        'title'       => "VPS {$vps->name} восстановлен",
        'message'     => 'TCP connect снова успешен, отклик 12 ms.',
        'source_id'   => $vps->id,
        'occurred_at' => now(),
        'resolved_at' => null,
    ]);

    $response = $this->actingAs($user)->get('/');

    $response->assertInertia(fn ($page) => $page
        ->component('Dashboard')
        ->has('dashboard.attentionItems', 0)   // VPS is online — nothing to attend
        ->has('dashboard.recentEvents', 2)     // both events visible in history
    );
});

test('dashboard uses current website measurements and ignores old website warnings', function (string $status) {
    $site = Website::create([
        'name' => 'Measured website', 'url' => 'https://example.com', 'type' => 'wordpress', 'enabled' => true,
        'status' => $status, 'last_http_status' => $status === 'unknown' ? null : ($status === 'online' ? 200 : 503),
        'last_response_ms' => $status === 'unknown' ? null : 37,
        'last_checked_at' => $status === 'unknown' ? null : now()->subMinute(),
    ]);
    foreach (['Website', 'wordpress'] as $type) {
        Event::create(['type' => $type, 'severity' => 'warning', 'title' => $site->name, 'message' => 'Old warning', 'source_id' => $site->id, 'occurred_at' => now()->subHour()]);
    }
    $site->refresh();
    $this->actingAs(User::factory()->create())->get('/')->assertInertia(fn ($page) => $page
        ->where('dashboard.websitesList.0.status', $status)
        ->where('dashboard.websitesList.0.last_http_status', $site->last_http_status)
        ->where('dashboard.websitesList.0.last_response_ms', $site->last_response_ms)
        ->where('dashboard.websitesList.0.last_checked_at', $site->last_checked_at?->toISOString())
        ->missing('dashboard.websitesList.0.sslDays')
        ->has('dashboard.attentionItems', $status === 'offline' ? 1 : 0)
        ->has('dashboard.recentEvents', 2)
        ->where('dashboard.overallStatus', $status === 'offline' ? 'warning' : 'ok')
        ->where('dashboard.summaries.websites.count', 1)
    );
})->with(['offline', 'online', 'unknown']);


test('offline website without events requires attention and disabled websites are excluded', function () {
    Website::create(['name' => 'Offline', 'url' => 'https://example.com', 'status' => 'offline', 'enabled' => true]);
    Website::create(['name' => 'Disabled', 'url' => 'https://disabled.example.com', 'status' => 'offline', 'enabled' => false]);
    $this->actingAs(User::factory()->create())->get('/')->assertInertia(fn ($page) => $page
        ->has('dashboard.attentionItems', 1)
        ->where('dashboard.attentionItems.0.category', 'Website')
        ->has('dashboard.websitesList', 1)
        ->where('dashboard.summaries.websites.count', 1)
        ->where('websiteCount', 2)
        ->has('dashboard.recentEvents', 0)
    );
});

test('website down and recovery update attention while preserving event history', function () {
    \Illuminate\Support\Facades\Http::preventStrayRequests();
    \Illuminate\Support\Facades\Http::fake([
        'https://example.test' => \Illuminate\Support\Facades\Http::sequence()->push('', 404)->push('', 404)->push('', 200),
    ]);
    $this->travelTo(now()->startOfSecond());
    $site = Website::create(['name' => 'Monitored', 'url' => 'https://example.test']);
    $notifier = $this->mock(\App\Services\MaxNotifier::class);
    $notifier->shouldNotReceive('sendWebsiteAggregate');
    $notifier->shouldNotReceive('sendWebsiteAggregateRecovery');
    $this->actingAs(User::factory()->create());
    $service = app(\App\Services\WebsiteMonitoringService::class);
    $service->monitor($site);
    $this->travel(10)->minutes();
    $service->monitor($site);
    $this->get('/')->assertInertia(fn ($page) => $page
        ->has('dashboard.attentionItems', 1)
        ->where('dashboard.attentionItems.0.id', 'website-offline-'.$site->id)
        ->has('dashboard.recentEvents', 1)
    );
    $this->travel(1)->minutes();
    $service->monitor($site);
    $this->get('/')->assertInertia(fn ($page) => $page
        ->has('dashboard.attentionItems', 0)
        ->has('dashboard.recentEvents', 2)
        ->where('dashboard.recentEvents.0.type', 'info')
        ->where('dashboard.recentEvents.1.type', 'warning')
    );
    expect(Event::count())->toBe(2)->and(Event::whereNotNull('resolved_at')->count())->toBe(0);
});
