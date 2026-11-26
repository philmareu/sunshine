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
        Schema::table('users', function (Blueprint $table) {
            // Day of the week, 0 (Sunday) to 6 (Saturday). Thursday, so new events land before weekend planning.
            $table->unsignedTinyInteger('landing_day')->default(4)->after('timezone');
            $table->json('free_all_day')->nullable()->after('landing_day');
            $table->json('free_evenings')->nullable()->after('free_all_day');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['landing_day', 'free_all_day', 'free_evenings']);
        });
    }
};
