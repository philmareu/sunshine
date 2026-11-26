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
        Schema::table('activities', function (Blueprint $table) {
            // When the family said never to suggest it again, and why.
            $table->timestamp('avoided_at')->nullable()->after('category');
            $table->text('avoid_reason')->nullable()->after('avoided_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->dropColumn(['avoided_at', 'avoid_reason']);
        });
    }
};
