<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Likes become a status that follows an event through its life: interested, not for us, went (with a
 * rating and a retrospective) or didn't go.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->renameColumn('reaction', 'status');
            $table->renameColumn('reaction_note', 'note');
            $table->renameColumn('reacted_at', 'marked_at');
        });

        Schema::table('events', function (Blueprint $table) {
            $table->unsignedTinyInteger('rating')->nullable()->after('note');
            $table->text('retrospective')->nullable()->after('rating');
        });

        DB::table('events')->where('status', 'liked')->where('attended', true)->update(['status' => 'went']);
        DB::table('events')->where('status', 'liked')->update(['status' => 'interested']);
        DB::table('events')->where('status', 'disliked')->update(['status' => 'not_for_us']);

        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('attended');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->boolean('attended')->default(false)->after('status');
        });

        DB::table('events')->where('status', 'went')->update(['status' => 'liked', 'attended' => true]);
        DB::table('events')->where('status', 'interested')->update(['status' => 'liked']);
        DB::table('events')->where('status', 'not_for_us')->update(['status' => 'disliked']);
        DB::table('events')->where('status', 'missed')->update(['status' => null]);

        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn(['rating', 'retrospective']);
            $table->renameColumn('status', 'reaction');
            $table->renameColumn('note', 'reaction_note');
            $table->renameColumn('marked_at', 'reacted_at');
        });
    }
};
