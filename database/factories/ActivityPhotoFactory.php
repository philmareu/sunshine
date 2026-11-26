<?php

namespace Database\Factories;

use App\Models\Activity;
use App\Models\ActivityPhoto;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActivityPhoto>
 */
class ActivityPhotoFactory extends Factory
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
            'path' => 'activity-photos/'.fake()->uuid().'.jpg',
        ];
    }
}
