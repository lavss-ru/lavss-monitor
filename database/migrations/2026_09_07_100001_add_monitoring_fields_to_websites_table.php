<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds HTTP health-check result columns to the websites table.
     * Stage 3.1 — manual HTTP/HTTPS monitoring.
     */
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->string('status')->default('unknown')->after('enabled');
            $table->timestamp('last_checked_at')->nullable()->after('status');
            $table->integer('last_response_ms')->nullable()->after('last_checked_at');
            $table->integer('last_http_status')->nullable()->after('last_response_ms');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->dropColumn(['status', 'last_checked_at', 'last_response_ms', 'last_http_status']);
        });
    }
};
