<?php

namespace App\Mcp\Tools;

use App\Models\User;
use Carbon\WeekDay;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('What Sunshine knows about this family: home, the other places they\'d go (every scan searches each in a run of its own), their time zone and today\'s date there, the days they\'re free, the day their weekly scan runs, what they\'re looking for and want to avoid, their budget, and the weekly summary of their taste the scout reads (null until a scan writes one). Read this before looking for sources or starting a search.')]
#[IsReadOnly]
class GetPreferences extends Tool
{
    public function handle(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Response::json([
            'home_location' => $user->home_location,
            'other_places' => $user->otherPlaces(),
            'timezone' => $user->timezone,
            'today' => $user->today()->toDateString(),
            'scan_day' => WeekDay::from($user->landing_day)->name,
            'scan_days_ahead' => config('sunshine.discovery.days_ahead'),
            'free_all_day' => $this->dayNames($user->free_all_day ?? []),
            'free_evenings' => $this->dayNames($user->free_evenings ?? []),
            'looking_for' => $user->interests ?? [],
            'avoid' => $user->avoid ?? [],
            'budget' => $user->budget,
            'taste_profile' => $user->taste_profile,
            'taste_profiled_at' => $user->taste_profiled_at?->toIso8601String(),
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    /**
     * @param  array<int|string>  $days
     * @return list<string>
     */
    protected function dayNames(array $days): array
    {
        return array_map(fn (int|string $day): string => WeekDay::from((int) $day)->name, array_values($days));
    }
}
