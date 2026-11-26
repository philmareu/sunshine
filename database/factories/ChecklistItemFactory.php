<?php

namespace Database\Factories;

use App\Models\Activity;
use App\Models\ChecklistItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChecklistItem>
 */
class ChecklistItemFactory extends Factory
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
            'label' => fake()->randomElement(['Bring cash', 'Sunscreen', 'Buy tickets ahead', 'Pack snacks']),
        ];
    }
}
