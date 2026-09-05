<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add source_id to events for linking to a VPS (or other resource).
     * Nullable — generic events without a specific source are allowed.
     * No FK constraint — events should survive resource deletion.
     */
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->unsignedBigInteger('source_id')->nullable()->after('message');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('source_id');
        });
    }
};
