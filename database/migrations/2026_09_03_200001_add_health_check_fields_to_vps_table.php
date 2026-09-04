<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('vps', function (Blueprint $table) {
            $table->string('status')->default('unknown')->after('enabled');
            $table->integer('check_port')->default(22)->after('status');
            $table->timestamp('last_checked_at')->nullable()->after('check_port');
            $table->integer('last_response_ms')->nullable()->after('last_checked_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vps', function (Blueprint $table) {
            $table->dropColumn(['status', 'check_port', 'last_checked_at', 'last_response_ms']);
        });
    }
};
