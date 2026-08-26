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
