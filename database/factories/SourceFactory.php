<?php

namespace Database\Factories;

use App\Models\Source;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Source>
 */
class SourceFactory extends Factory
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
            'name' => fake()->company().' events',
            'url' => fake()->url(),
        ];
    }

    /**
     * A source the last scan read, with the scout's note on it.
     */
    public function checked(string $note = 'Two events in range, both included.'): static
    {
        return $this->state(fn (): array => [
            'checked_at' => now(),
            'page_read' => true,
            'check_note' => $note,
        ]);
    }
}
