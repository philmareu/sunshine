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
            $table->text('taste_profile')->nullable()->after('budget');
            $table->timestamp('taste_profiled_at')->nullable()->after('taste_profile');
        });

        Schema::table('ai_usages', function (Blueprint $table) {
            $table->string('purpose')->nullable()->after('source_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['taste_profile', 'taste_profiled_at']);
        });

        Schema::table('ai_usages', function (Blueprint $table) {
            $table->dropColumn('purpose');
        });
    }
};
