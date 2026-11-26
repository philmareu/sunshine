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
        Schema::table('sources', function (Blueprint $table) {
            $table->timestamp('checked_at')->nullable()->after('url');
            $table->boolean('page_read')->nullable()->after('checked_at');
            $table->text('check_note')->nullable()->after('page_read');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->dropColumn(['checked_at', 'page_read', 'check_note']);
        });
    }
};
