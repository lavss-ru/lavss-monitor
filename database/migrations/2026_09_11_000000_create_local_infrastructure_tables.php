<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('connection_type', 32)->default('local');
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
        Schema::create('local_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('type', 32);
            $table->string('host', 253);
            $table->unsignedInteger('check_port');
            $table->boolean('enabled')->default(true);
            $table->text('description')->nullable();
            $table->string('status', 16)->default('unknown');
            $table->timestamp('last_checked_at')->nullable();
            $table->unsignedInteger('last_response_ms')->nullable();
            $table->timestamp('failure_started_at')->nullable();
            $table->timestamp('incident_confirmed_at')->nullable();
            $table->timestamp('incident_notified_at')->nullable();
            $table->timestamp('recovery_pending_at')->nullable();
            $table->timestamps();
            $table->index(['location_id', 'enabled']);
            $table->index(['enabled', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('local_devices');
        Schema::dropIfExists('locations');
    }
};
