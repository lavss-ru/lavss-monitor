<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->boolean('monitoring_enabled')->default(false);
            $table->enum('status', ['online', 'offline', 'unknown'])->default('unknown');
            $table->enum('probe_type', ['tcp'])->default('tcp');
            $table->string('probe_host', 253)->nullable();
            $table->unsignedSmallInteger('probe_port')->nullable();
            $table->unsignedInteger('last_response_ms')->nullable();
            foreach (['last_checked_at', 'failure_started_at', 'incident_confirmed_at', 'incident_notified_at', 'recovery_pending_at', 'wireguard_last_handshake_at'] as $field) {
                $table->timestamp($field)->nullable();
            }
            $table->string('wireguard_interface', 15)->nullable();
            $table->string('wireguard_peer_public_key', 44)->nullable();
            $table->string('wireguard_diagnostic_state')->default('unknown');
            $table->unsignedBigInteger('wireguard_rx_bytes')->nullable();
            $table->unsignedBigInteger('wireguard_tx_bytes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('locations', fn (Blueprint $table) => $table->dropColumn([
            'monitoring_enabled', 'status', 'probe_type', 'probe_host', 'probe_port', 'last_response_ms',
            'last_checked_at', 'failure_started_at', 'incident_confirmed_at', 'incident_notified_at',
            'recovery_pending_at', 'wireguard_interface', 'wireguard_peer_public_key',
            'wireguard_diagnostic_state', 'wireguard_last_handshake_at', 'wireguard_rx_bytes', 'wireguard_tx_bytes',
        ]));
    }
};
