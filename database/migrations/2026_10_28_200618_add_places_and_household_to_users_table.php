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
            // Other places they'd go besides home: [{location, note}].
            $table->json('places')->nullable()->after('home_location');
            // Who goes along: [{name, born_in, likes}].
            $table->json('household')->nullable()->after('free_evenings');
            $table->string('budget')->nullable()->after('avoid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['places', 'household', 'budget']);
        });
    }
};
