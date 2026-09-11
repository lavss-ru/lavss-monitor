<?php

use App\Models\Event;
use App\Models\LocalDevice;
use App\Models\Location;
use App\Models\User;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/../Support/LocalInfrastructure.php';

beforeEach(function () {
    Http::preventStrayRequests();
    Http::fake();
});

test('local routes require authentication', function (string $method, string $path) {
    $this->{$method}($path)->assertRedirect('/login');
    Http::assertNothingSent();
})->with([
    ['get', '/local-infrastructure'], ['get', '/locations'], ['post', '/locations'],
    ['put', '/locations/1'], ['delete', '/locations/1'], ['post', '/local-devices'],
    ['put', '/local-devices/1'], ['delete', '/local-devices/1'],
    ['post', '/local-devices/1/check'], ['post', '/local-devices/check-all'],
]);

test('owner lists grouped locations and creates updates deletes an empty location', function () {
    $this->actingAs(User::factory()->create());
    $this->post('/locations', ['name' => 'Лаборатория', 'description' => 'Тест', 'connection_type' => 'wireguard', 'enabled' => true])->assertRedirect('/local-infrastructure');
    $location = Location::sole();
    $this->get('/locations')->assertInertia(fn ($p) => $p->component('LocalInfrastructure/Index')->has('locations', 1)
        ->where('locations.0.connection_type', 'wireguard')->has('locations.0.devices', 0));
    $this->put("/locations/{$location->id}", ['name' => 'Новая площадка', 'connection_type' => 'vpn', 'enabled' => false])->assertSessionHasNoErrors();
    expect($location->fresh()->name)->toBe('Новая площадка')->and($location->fresh()->enabled)->toBeFalse();
    $this->delete("/locations/{$location->id}")->assertRedirect('/local-infrastructure');
    expect(Location::count())->toBe(0);
});

test('location validates fields', function (array $changes, string $field) {
    $this->actingAs(User::factory()->create())->post('/locations', array_merge([
        'name' => 'Test', 'connection_type' => 'local', 'enabled' => true,
    ], $changes))->assertSessionHasErrors($field);
    expect(Location::count())->toBe(0);
})->with([[['name' => ''], 'name'], [['name' => str_repeat('x', 256)], 'name'], [['connection_type' => 'ssh'], 'connection_type'], [['enabled' => 'yes'], 'enabled'], [['description' => str_repeat('x', 5001)], 'description']]);

test('all stable location connection types accepted', function (string $type) {
    $this->actingAs(User::factory()->create())->post('/locations', ['name' => 'Test', 'connection_type' => $type, 'enabled' => true])->assertSessionHasNoErrors();
    expect(Location::sole()->connection_type)->toBe($type);
})->with(Location::CONNECTION_TYPES);

test('nonempty location deletion is blocked in controller and foreign key', function () {
    $device = localDevice();
    $this->actingAs(User::factory()->create())->delete("/locations/{$device->location_id}")->assertSessionHasErrors('location');
    expect(LocalDevice::count())->toBe(1)->and(Location::count())->toBe(1);
    expect(fn () => $device->location->delete())->toThrow(\Illuminate\Database\QueryException::class);
});

test('owner creates edits lists and deletes local device', function () {
    $location = localLocation();
    $this->actingAs(User::factory()->create())->post('/local-devices', ['location_id' => $location->id,
        'name' => 'Test device', 'type' => 'proxmox', 'host' => '192.168.31.10', 'check_port' => 8006, 'enabled' => true])->assertSessionHasNoErrors();
    $device = LocalDevice::sole();
    expect($device->status)->toBe('unknown')->and($device->last_checked_at)->toBeNull();
    $this->put("/local-devices/{$device->id}", localPayload($device, ['name' => 'Edited', 'description' => 'Description']))->assertSessionHasNoErrors();
    $this->get('/local-infrastructure')->assertInertia(fn ($p) => $p->has('locations.0.devices', 1)->where('locations.0.devices.0.name', 'Edited'));
    $this->delete("/local-devices/{$device->id}")->assertRedirect('/local-infrastructure');
    expect(LocalDevice::count())->toBe(0)->and(Location::count())->toBe(1);
});

test('device accepts private ipv4 ipv6 and DNS hosts', function (string $host) {
    $device = localDevice();
    $this->actingAs(User::factory()->create())->post('/local-devices', localPayload($device, ['host' => $host]))->assertSessionHasNoErrors();
    expect(LocalDevice::orderByDesc('id')->first()->host)->toBe($host);
})->with(['192.168.31.10', '10.10.10.5', '172.16.1.2', 'pve.internal', 'router', 'PVE.example.', '::1', 'fd00::1234', '2001:db8::1']);

test('device rejects malformed or dangerous host', function (string $host) {
    $device = localDevice();
    $this->actingAs(User::factory()->create())->post('/local-devices', localPayload($device, ['host' => $host]))->assertSessionHasErrors('host');
    expect(LocalDevice::count())->toBe(1);
})->with(['https://pve.internal', 'tcp://10.0.0.1', 'host:22', '[::1]', 'host/path', 'user@host', 'a..b', '-host', 'host-', 'a_b', '999.999.999.999', 'host;id', '$(id)', '`id`', "host\nname", 'fe80::1%eth0', str_repeat('a', 64).'.internal']);

test('device validates required fields and port bounds on create and update', function (array $changes, string $field) {
    $device = localDevice();
    $this->actingAs(User::factory()->create());
    $data = localPayload($device, $changes);
    $this->post('/local-devices', $data)->assertSessionHasErrors($field);
    $this->put("/local-devices/{$device->id}", $data)->assertSessionHasErrors($field);
    expect(LocalDevice::count())->toBe(1);
})->with([
    [['location_id' => null], 'location_id'], [['location_id' => 99999], 'location_id'], [['name' => ''], 'name'],
    [['name' => str_repeat('x', 256)], 'name'], [['type' => 'pve_api'], 'type'], [['host' => ''], 'host'],
    [['check_port' => 0], 'check_port'], [['check_port' => 65536], 'check_port'], [['check_port' => 22.5], 'check_port'],
    [['check_port' => null], 'check_port'], [['enabled' => 'yes'], 'enabled'],
]);

test('device accepts all types and boundary ports', function (string $type, int $port) {
    $device = localDevice();
    $this->actingAs(User::factory()->create())->post('/local-devices', localPayload($device, ['type' => $type, 'check_port' => $port]))->assertSessionHasNoErrors();
})->with(LocalDevice::TYPES)->with([1, 65535]);

test('endpoint changes enabled changes and moves reset state without notifications', function (string $change) {
    $device = localDevice(['status' => 'offline', 'last_checked_at' => now(), 'last_response_ms' => 1,
        'failure_started_at' => now()->subMinutes(3), 'incident_confirmed_at' => now(),
        'incident_notified_at' => now(), 'recovery_pending_at' => now()]);
    $value = match ($change) { 'host' => 'other.internal', 'check_port' => 443, 'enabled' => false, 'location_id' => localLocation()->id };
    $this->actingAs(User::factory()->create())->put("/local-devices/{$device->id}", localPayload($device, [$change => $value]))->assertSessionHasNoErrors();
    $device->refresh();
    foreach (LocalDevice::RESET_STATE as $key => $expected) expect($device->$key)->toBe($expected);
    if ($change === 'enabled') {
        $this->put("/local-devices/{$device->id}", localPayload($device, ['enabled' => true]))->assertSessionHasNoErrors();
        expect($device->fresh()->enabled)->toBeTrue()->and($device->fresh()->status)->toBe('unknown');
    }
    Http::assertNothingSent();
})->with(['host', 'check_port', 'enabled', 'location_id']);

test('metadata edits preserve health and incident timer', function () {
    $device = localDevice(['status' => 'offline', 'failure_started_at' => now()]);
    $this->actingAs(User::factory()->create())->put("/local-devices/{$device->id}", localPayload($device, ['name' => 'Renamed', 'type' => 'vm']))->assertSessionHasNoErrors();
    expect($device->fresh()->status)->toBe('offline')->and($device->fresh()->failure_started_at->equalTo($device->failure_started_at))->toBeTrue();
});

test('disabling location resets children and resolves warnings without recovery event', function () {
    $device = localDevice(['status' => 'offline', 'incident_confirmed_at' => now(), 'incident_notified_at' => now()]);
    $event = Event::create(['type' => 'local_device', 'source_id' => $device->id, 'severity' => 'warning', 'title' => 'Down', 'message' => 'Down', 'occurred_at' => now()]);
    $this->actingAs(User::factory()->create())->put("/locations/{$device->location_id}", ['name' => 'Paused', 'connection_type' => 'vpn', 'enabled' => false])->assertSessionHasNoErrors();
    expect($device->fresh()->status)->toBe('unknown')->and($device->fresh()->incident_confirmed_at)->toBeNull()->and($event->fresh()->resolved_at)->not->toBeNull();
    expect(Event::count())->toBe(1);
    Http::assertNothingSent();
});

test('dashboard counts effective enabled devices by factual status', function () {
    localDevice(['status' => 'online']); localDevice(['status' => 'offline']); localDevice();
    localDevice(['status' => 'offline', 'enabled' => false]);
    localDevice(['location_id' => localLocation(['enabled' => false])->id]);
    $this->actingAs(User::factory()->create())->get('/')->assertInertia(fn ($p) => $p
        ->where('dashboard.localDevices.total', 3)->where('dashboard.localDevices.online', 1)
        ->where('dashboard.localDevices.offline', 1)->where('dashboard.localDevices.unknown', 1)
        ->where('dashboard.summaries.vps.count', 0)->where('dashboard.summaries.websites.count', 0));
});
