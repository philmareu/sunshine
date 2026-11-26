<?php

namespace Database\Factories;

use App\Enums\EventStatus;
use App\Models\Activity;
use App\Models\Event;
use App\Models\Search;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    /**
     * Define the model's default state. Unless given one, each event is the first time a new activity of the
     * search's user came up.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'search_id' => Search::factory(),
            'title' => fake()->sentence(3),
            'description' => fake()->paragraph(),
            'starts_on' => today()->addDays(fake()->numberBetween(0, 7)),
            'time' => '7:00 PM',
            'venue' => fake()->company(),
            'city' => fake()->city(),
            'region' => fake()->state(),
            'country' => 'US',
            'category' => fake()->randomElement(['Music', 'Sports', 'Class', 'Festival']),
            'url' => fake()->url(),
            'reason' => fake()->sentence(),
            'activity_id' => fn (array $attributes): Factory => Activity::factory()->state([
                'user_id' => Search::query()->findOrFail($attributes['search_id'])->user_id,
                ...array_intersect_key($attributes, array_flip(['title', 'description', 'venue', 'city', 'region', 'country', 'category'])),
            ]),
        ];
    }

    public function marked(EventStatus $status): static
    {
        return $this->state(['status' => $status, 'marked_at' => now()]);
    }
}
