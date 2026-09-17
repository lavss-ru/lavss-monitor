<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function types(array $types): void
    {
        foreach (['monitor_checks', 'notification_rules'] as $name) {
            if (DB::getDriverName() === 'pgsql') {
                // Laravel enum is a varchar with a named CHECK on PostgreSQL.
                DB::statement("ALTER TABLE {$name} DROP CONSTRAINT {$name}_monitor_type_check");
                $values = implode(', ', array_map(fn ($type) => "'{$type}'", $types));
                DB::statement("ALTER TABLE {$name} ADD CONSTRAINT {$name}_monitor_type_check CHECK (monitor_type IN ({$values}))");
            } else {
                // SQLite introspection loses enum CHECK definitions on unchanged columns.
                // Re-declare every enum during the rebuild, preserving data and indexes.
                Schema::table($name, function (Blueprint $table) use ($name, $types) {
                    $table->enum('monitor_type', $types)->change();
                    if ($name === 'monitor_checks') {
                        $table->enum('origin', ['scheduled', 'manual', 'manual_batch'])->change();
                        $table->enum('status', ['online', 'offline', 'unknown'])->change();
                    }
                });
            }
        }
    }

    private const TABLES = ['proxmox_connections', 'proxmox_nodes', 'proxmox_guests'];

    private const TYPES = ['proxmox_connection', 'proxmox_node', 'proxmox_guest'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->boolean('monitoring_enabled')->default($name !== 'proxmox_guests');
                foreach (['failure_started_at', 'incident_confirmed_at', 'incident_notified_at', 'recovery_pending_at'] as $field) {
                    $table->timestamp($field)->nullable();
                }
                if ($name === 'proxmox_guests') {
                    $table->enum('expected_status', ['running', 'stopped', 'ignore'])->default('running');
                }
            });
        }
        $this->types(array_merge(['vps', 'website', 'local_device', 'location'], self::TYPES));
        foreach (self::TYPES as $type) {
            DB::table('notification_rules')->insert(['monitor_type' => $type, 'down_enabled' => true,
                'recovery_enabled' => true, 'confirmation_seconds' => 120, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        foreach (['monitor_checks', 'notification_rules'] as $name) {
            DB::table($name)->whereIn('monitor_type', self::TYPES)->delete();
        }
        $this->types(['vps', 'website', 'local_device', 'location']);
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->dropColumn(['monitoring_enabled', 'failure_started_at', 'incident_confirmed_at', 'incident_notified_at', 'recovery_pending_at']);
                if ($name === 'proxmox_guests') {
                    $table->dropColumn('expected_status');
                }
            });
        }
    }
};
