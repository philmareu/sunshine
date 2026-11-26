<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Preferences describe the family as a whole, not each person in it. What anyone in the household likes joins
 * the family's interests, so nothing typed in is lost.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('users')->whereNotNull('household')->get(['id', 'interests', 'household'])->each(function (object $user): void {
            $likes = collect(json_decode($user->household, true) ?? [])->pluck('likes')->flatten()->filter();

            if ($likes->isEmpty()) {
                return;
            }

            DB::table('users')->where('id', $user->id)->update([
                'interests' => json_encode(collect(json_decode($user->interests ?? '[]', true))->merge($likes)->unique()->values()),
            ]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('household');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('household')->nullable()->after('free_evenings');
        });
    }
};
