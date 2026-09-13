<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vps', function (Blueprint $table) {
            foreach (['failure_started_at', 'incident_confirmed_at', 'incident_notified_at', 'recovery_pending_at'] as $field) {
                $table->timestamp($field)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('vps', fn (Blueprint $table) => $table->dropColumn([
            'failure_started_at', 'incident_confirmed_at', 'incident_notified_at', 'recovery_pending_at',
        ]));
    }
};
