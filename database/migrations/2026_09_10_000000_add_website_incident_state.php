<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->timestamp('failure_started_at')->nullable();
            $table->timestamp('incident_confirmed_at')->nullable();
            $table->timestamp('incident_notified_at')->nullable();
        });
        Schema::create('website_aggregate_states', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->timestamp('published_at')->nullable();
            $table->json('snapshot')->nullable();
            $table->string('fingerprint', 64)->nullable();
        });
        DB::table('website_aggregate_states')->insert(['id' => 1]);
    }

    public function down(): void
    {
        Schema::dropIfExists('website_aggregate_states');
        Schema::table('websites', function (Blueprint $table) {
            $table->dropColumn(['failure_started_at', 'incident_confirmed_at', 'incident_notified_at']);
        });
    }
};
