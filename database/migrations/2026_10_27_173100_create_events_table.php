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
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('search_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->date('starts_on');
            $table->string('time')->nullable();
            $table->string('venue')->nullable();
            $table->string('town')->nullable();
            $table->string('category')->nullable();
            $table->string('cost')->nullable();
            $table->string('url', 2048);
            $table->text('reason')->nullable();
            $table->boolean('is_wildcard')->default(false);
            $table->string('reaction')->nullable();
            $table->boolean('attended')->default(false);
            $table->text('reaction_note')->nullable();
            $table->timestamp('reacted_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
