<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proxmox_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->index()->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('host', 253);
            $table->unsignedInteger('port')->default(8006);
            $table->enum('scheme', ['https', 'http'])->default('https');
            $table->boolean('verify_tls')->default(true);
            $table->string('api_user');
            $table->string('api_token_id');
            $table->text('api_token_secret');
            $table->boolean('enabled')->default(true);
            $table->enum('status', ['online', 'offline', 'unknown'])->default('unknown')->index();
            $table->timestamp('last_checked_at')->nullable();
            $table->unsignedInteger('last_response_ms')->nullable();
            $table->string('last_error_code', 40)->nullable();
            $table->string('version')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            // Optimistic guard against edits and overlapping fetches.
            $table->unsignedBigInteger('revision')->default(0);
            $table->timestamps();
        });
        Schema::create('proxmox_nodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proxmox_connection_id')->constrained()->cascadeOnDelete();
            $table->string('node_name');
            $table->enum('status', ['online', 'offline', 'unknown'])->default('unknown')->index();
            $table->double('cpu_usage')->nullable();
            $table->unsignedBigInteger('memory_used')->nullable();
            $table->unsignedBigInteger('memory_total')->nullable();
            $table->unsignedBigInteger('uptime_seconds')->nullable();
            $table->unsignedInteger('max_cpu')->nullable();
            $table->string('version')->nullable();
            $table->boolean('stale')->default(false);
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamps();
            $table->unique(['proxmox_connection_id', 'node_name']);
        });
        Schema::create('proxmox_guests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proxmox_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('proxmox_node_id')->nullable()->index()->constrained()->nullOnDelete();
            $table->enum('guest_type', ['qemu', 'lxc']);
            $table->unsignedInteger('vmid');
            $table->string('name')->nullable();
            $table->enum('status', ['running', 'stopped', 'paused', 'unknown'])->default('unknown')->index();
            $table->double('cpu_usage')->nullable();
            foreach (['memory_used', 'memory_total', 'disk_used', 'disk_total', 'uptime_seconds'] as $field) {
                $table->unsignedBigInteger($field)->nullable();
            }
            $table->unsignedInteger('max_cpu')->nullable();
            $table->boolean('template')->default(false);
            $table->boolean('stale')->default(false);
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamps();
            $table->unique(['proxmox_connection_id', 'guest_type', 'vmid'], 'proxmox_guests_identity_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proxmox_guests');
        Schema::dropIfExists('proxmox_nodes');
        Schema::dropIfExists('proxmox_connections');
    }
};
