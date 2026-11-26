<?php

namespace Database\Factories;

use App\Enums\SearchStatus;
use App\Models\Search;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Search>
 */
class SearchFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'location' => fake()->city(),
            'starts_on' => today(),
            'ends_on' => today()->addWeek(),
            'status' => SearchStatus::Pending,
        ];
    }
}
