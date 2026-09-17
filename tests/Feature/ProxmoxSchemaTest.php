<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/../Support/Proxmox.php';

test('proxmox SQLite migration reverses and recreates indexed typed tables', function () {
    $migration = require database_path('migrations/2026_09_15_000000_create_proxmox_inventory.php');
    $c = pveConnection(['port' => 65535]);
    expect($c->port)->toBe(65535);
    expect(Schema::hasIndex('proxmox_nodes', ['proxmox_connection_id', 'node_name'], 'unique'))->toBeTrue()
        ->and(Schema::hasIndex('proxmox_guests', ['proxmox_connection_id', 'guest_type', 'vmid'], 'unique'))->toBeTrue()
        ->and(Schema::hasIndex('proxmox_guests', ['last_seen_at']))->toBeTrue()
        ->and(Schema::hasIndex('proxmox_guests', ['proxmox_node_id']))->toBeTrue()
        ->and(Schema::hasIndex('proxmox_connections', ['location_id']))->toBeTrue();
    $migration->down();
    expect(Schema::hasTable('proxmox_connections'))->toBeFalse()->and(Schema::hasTable('proxmox_nodes'))->toBeFalse()
        ->and(Schema::hasTable('proxmox_guests'))->toBeFalse();
    $migration->up();
    expect(Schema::hasTable('proxmox_connections'))->toBeTrue();
});

test('proxmox SQLite enforces guest identity and typed status', function (string $variant) {
    $c = pveConnection();
    $c->guests()->create(['guest_type' => 'qemu', 'vmid' => 100, 'status' => 'running']);
    expect(fn () => $c->guests()->create(['guest_type' => 'qemu',
        'vmid' => $variant === 'duplicate' ? 100 : 101, 'status' => $variant === 'duplicate' ? 'stopped' : 'invalid']))
        ->toThrow(QueryException::class);
})->with(['duplicate', 'status']);

test('proxmox PostgreSQL DDL is generated offline without executing SQL', function () {
    $original = DB::getDefaultConnection();
    config(['database.connections.stage38_sql_only' => ['driver' => 'pgsql', 'database' => 'unused', 'prefix' => '']]);
    DB::setDefaultConnection('stage38_sql_only');
    $connection = DB::connection();
    $pdo = Mockery::mock(PDO::class);
    $pdo->shouldReceive('quote')->andReturnUsing(fn ($value) => "'".str_replace("'", "''", $value)."'");
    $pdo->shouldNotReceive('prepare');
    $pdo->shouldNotReceive('exec');
    $connection->setPdo($pdo);
    $connection->setReadPdo($pdo);
    $originalSchema = Schema::getFacadeRoot();
    Schema::swap($connection->getSchemaBuilder());
    try {
        $migration = require database_path('migrations/2026_09_15_000000_create_proxmox_inventory.php');
        $sql = implode("\n", array_column($connection->pretend(fn () => $migration->up()), 'query'));
        expect($sql)->toContain('"port" integer not null default')
            ->toContain('"api_token_secret" text not null')
            ->toContain('"memory_total" bigint null')
            ->toContain("check (\"guest_type\" in ('qemu', 'lxc'))")
            ->toContain('on delete cascade')->toContain('on delete restrict')
            ->toContain('proxmox_guests_identity_unique');
        $down = implode("\n", array_column($connection->pretend(fn () => $migration->down()), 'query'));
        expect($down)->toContain('drop table if exists "proxmox_guests"', 'drop table if exists "proxmox_nodes"', 'drop table if exists "proxmox_connections"');
    } finally {
        Schema::swap($originalSchema);
        DB::setDefaultConnection($original);
        DB::purge('stage38_sql_only');
    }
});

test('proxmox monitoring migration preserves existing production shaped guests with monitoring off', function () {
    $migration = require database_path('migrations/2026_09_16_000000_add_proxmox_monitoring.php');
    $migration->down();
    $c = pveConnection(['name' => 'PVE Home']);
    $node = $c->nodes()->create(['node_name' => 'proxmox', 'status' => 'online']);
    foreach ([100, 102, 103, 105, 110, 200, 300, 500] as $vmid) {
        $c->guests()->create(['proxmox_node_id' => $node->id, 'guest_type' => 'qemu', 'vmid' => $vmid,
            'status' => in_array($vmid, [103, 200]) ? 'stopped' : 'running']);
    }
    $migration->up();
    expect($c->fresh()->name)->toBe('PVE Home')->and($c->fresh()->monitoring_enabled)->toBeTrue()
        ->and($node->fresh()->monitoring_enabled)->toBeTrue()->and($c->guests()->count())->toBe(8)
        ->and($c->guests()->where('monitoring_enabled', false)->where('expected_status', 'running')->count())->toBe(8)
        ->and(DB::table('notification_rules')->count())->toBe(7)
        ->and(Schema::hasIndex('monitor_checks', 'monitor_checks_statistics_index'))->toBeTrue();
    $migration->down();
    expect($c->guests()->count())->toBe(8)->and(Schema::hasColumn('proxmox_guests', 'monitoring_enabled'))->toBeFalse()
        ->and(DB::table('notification_rules')->count())->toBe(4);
    $migration->up();
});

test('proxmox monitoring PostgreSQL DDL compiles offline including reversible checks', function () {
    $original = DB::getDefaultConnection();
    config(['database.connections.stage381_sql_only' => ['driver' => 'pgsql', 'database' => 'unused', 'prefix' => '']]);
    DB::setDefaultConnection('stage381_sql_only');
    $connection = DB::connection();
    $pdo = Mockery::mock(PDO::class);
    $pdo->shouldReceive('quote')->andReturnUsing(fn ($value) => "'".str_replace("'", "''", $value)."'");
    $pdo->shouldNotReceive('prepare');
    $pdo->shouldNotReceive('exec');
    $connection->setPdo($pdo);
    $connection->setReadPdo($pdo);
    $originalSchema = Schema::getFacadeRoot();
    Schema::swap($connection->getSchemaBuilder());
    try {
        $migration = require database_path('migrations/2026_09_16_000000_add_proxmox_monitoring.php');
        $sql = implode("\n", array_column($connection->pretend(fn () => $migration->up()), 'query'));
        expect($sql)->toContain('"monitoring_enabled" boolean not null default')
            ->toContain('"incident_confirmed_at" timestamp(0) without time zone null')
            ->toContain("check (\"expected_status\" in ('running', 'stopped', 'ignore'))")
            ->toContain('DROP CONSTRAINT monitor_checks_monitor_type_check')
            ->toContain("'proxmox_connection', 'proxmox_node', 'proxmox_guest'");
        $down = implode("\n", array_column($connection->pretend(fn () => $migration->down()), 'query'));
        expect($down)->toContain('drop column "monitoring_enabled"')->toContain("CHECK (monitor_type IN ('vps', 'website', 'local_device', 'location'))");
    } finally {
        Schema::swap($originalSchema);
        DB::setDefaultConnection($original);
        DB::purge('stage381_sql_only');
    }
});
