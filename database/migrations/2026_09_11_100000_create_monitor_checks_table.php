<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitor_checks', function (Blueprint $table) {
            $table->id();
            $table->enum('monitor_type', ['vps', 'website', 'local_device']);
            $table->unsignedBigInteger('monitor_id');
            $table->enum('origin', ['scheduled', 'manual', 'manual_batch']);
            $table->enum('status', ['online', 'offline', 'unknown']);
            $table->timestamp('checked_at');
            $table->unsignedInteger('response_ms')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->index(['monitor_type', 'monitor_id', 'origin', 'checked_at'], 'monitor_checks_statistics_index');
            $table->index('checked_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitor_checks');
    }
};
