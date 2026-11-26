<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Every event becomes one time an activity came up. Events the scout linked to an earlier visit join that
 * visit's activity, and events with the same title in the same city share one. The link to the previous
 * visit goes: the activity now holds every visit.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->unsignedBigInteger('activity_id')->nullable()->after('search_id');
        });

        $events = DB::table('events')
            ->join('searches', 'searches.id', '=', 'events.search_id')
            ->orderBy('events.starts_on')
            ->orderBy('events.id')
            ->select('events.*', 'searches.user_id')
            ->get();

        // Activities by user, title and city, and the activity each event joined. Visits come before the
        // events that link to them, since they are earlier.
        $activities = [];
        $assigned = [];

        foreach ($events as $event) {
            $key = $event->user_id.' '.Str::of($event->title)->lower()->squish().' '.Str::of((string) $event->city)->lower()->squish();
            $visitActivityId = $event->previous_visit_id === null ? null : ($assigned[$event->previous_visit_id] ?? null);

            $activityId = $visitActivityId ?? $activities[$key] ?? DB::table('activities')->insertGetId([
                'user_id' => $event->user_id,
                'title' => $event->title,
                'description' => $event->description,
                'venue' => $event->venue,
                'city' => $event->city,
                'region' => $event->region,
                'country' => $event->country,
                'category' => $event->category,
                'created_at' => $event->created_at,
                'updated_at' => $event->created_at,
            ]);

            $activities[$key] ??= $activityId;
            $assigned[$event->id] = $activityId;

            DB::table('events')->where('id', $event->id)->update(['activity_id' => $activityId]);
        }

        Schema::table('events', function (Blueprint $table) {
            $table->unsignedBigInteger('activity_id')->nullable(false)->change();
            $table->foreign('activity_id')->references('id')->on('activities');
            $table->dropConstrainedForeignId('previous_visit_id');
        });
    }

    /**
     * Reverse the migrations. Each event links back to the latest visit of its activity before it.
     */
    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->foreignId('previous_visit_id')->nullable()->after('search_id')->constrained('events')->nullOnDelete();
        });

        foreach (DB::table('events')->get() as $event) {
            $visit = DB::table('events')
                ->where('activity_id', $event->activity_id)
                ->where('status', 'went')
                ->where('starts_on', '<', $event->starts_on)
                ->orderByDesc('starts_on')
                ->value('id');

            if ($visit !== null) {
                DB::table('events')->where('id', $event->id)->update(['previous_visit_id' => $visit]);
            }
        }

        Schema::table('events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('activity_id');
        });
    }
};
