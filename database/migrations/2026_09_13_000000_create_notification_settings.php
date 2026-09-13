<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->boolean('notifications_enabled')->default(true);
            $table->boolean('max_enabled')->default(true);
            $table->string('max_recipient_id', 255)->nullable();
            $table->string('timezone')->default('Europe/Moscow');
            $table->boolean('quiet_hours_enabled')->default(false);
            $table->time('quiet_hours_start')->nullable();
            $table->time('quiet_hours_end')->nullable();
            $table->timestamps();
        });
        Schema::create('notification_rules', function (Blueprint $table) {
            $table->id();
            $table->enum('monitor_type', ['vps', 'website', 'local_device'])->unique();
            $table->boolean('down_enabled')->default(true);
            $table->boolean('recovery_enabled')->default(true);
            $table->unsignedInteger('confirmation_seconds');
            $table->timestamps();
        });
        DB::table('notification_settings')->insert(['id' => 1, 'created_at' => now(), 'updated_at' => now()]);
        foreach (['vps' => 0, 'website' => 600, 'local_device' => 120] as $type => $delay) {
            DB::table('notification_rules')->insert(['monitor_type' => $type, 'confirmation_seconds' => $delay,
                'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_rules');
        Schema::dropIfExists('notification_settings');
    }
};
