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
            // How many events Sunshine read on the page last run, or null when it was left to the scout's fetch, and
            // the reader's note on it, or why Sunshine couldn't read it.
            $table->unsignedSmallInteger('events_read')->nullable()->after('page_read');
            $table->text('read_note')->nullable()->after('events_read');
        });

        Schema::table('ai_usages', function (Blueprint $table) {
            // The page a usage read, for Sunshine's own reads of watched pages; null for the scout's runs.
            $table->foreignId('source_id')->nullable()->after('search_id')->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_usages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_id');
        });

        Schema::table('sources', function (Blueprint $table) {
            $table->dropColumn(['events_read', 'read_note']);
        });
    }
};
