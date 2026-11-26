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
            // Which of the user's other places a scan opens it in; null for the run near home.
            $table->string('place')->nullable()->after('url');
            // The one ad hoc search it is for; null for a page every scan opens.
            $table->foreignId('search_id')->nullable()->after('user_id')->constrained()->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->dropConstrainedForeignId('search_id');
            $table->dropColumn('place');
        });
    }
};
