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

    public function up(): void
    {
        $this->types(['vps', 'website', 'local_device', 'location']);
        DB::table('notification_rules')->insert(['monitor_type' => 'location', 'down_enabled' => true,
            'recovery_enabled' => true, 'confirmation_seconds' => 120, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Old schema cannot represent location rows. Only this new type is removed.
        DB::table('monitor_checks')->where('monitor_type', 'location')->delete();
        DB::table('notification_rules')->where('monitor_type', 'location')->delete();
        $this->types(['vps', 'website', 'local_device']);
    }
};
