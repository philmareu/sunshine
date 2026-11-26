<?php

namespace Database\Factories;

use App\Models\Activity;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Activity>
 */
class ActivityFactory extends Factory
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
            'title' => fake()->sentence(3),
            'description' => fake()->paragraph(),
            'venue' => fake()->company(),
            'city' => fake()->city(),
            'region' => fake()->state(),
            'country' => 'US',
            'category' => fake()->randomElement(['Music', 'Sports', 'Class', 'Festival']),
        ];
    }

    /**
     * One the family never wants suggested again.
     */
    public function avoided(?string $reason = 'Too crowded for the kids.'): static
    {
        return $this->state(fn (): array => ['avoided_at' => now(), 'avoid_reason' => $reason]);
    }
}
