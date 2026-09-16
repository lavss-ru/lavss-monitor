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
