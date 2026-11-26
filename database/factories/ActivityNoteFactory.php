<?php

namespace Database\Factories;

use App\Models\Activity;
use App\Models\ActivityNote;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActivityNote>
 */
class ActivityNoteFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'activity_id' => Activity::factory(),
            'body' => fake()->sentence(),
        ];
    }
}
