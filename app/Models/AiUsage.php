<?php

namespace App\Models;

use App\Ai\Pricing;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\ProviderToolCall;
use Laravel\Ai\Responses\Data\Step;

/**
 * The tokens, web searches, pages fetched and cost of one AI run: a scout's run, Sunshine's own read of one of the
 * pages a run opens (with its source), or the weekly write-up of the family's taste (with its purpose).
 */
#[Fillable([
    'user_id', 'search_id', 'source_id', 'purpose', 'location', 'provider', 'model', 'input_tokens', 'cache_write_tokens', 'cache_read_tokens',
    'output_tokens', 'web_searches', 'pages_fetched', 'cost',
])]
class AiUsage extends Model
{
    /**
     * The purpose of writing a family's taste profile (TasteProfile), which belongs to no search. Scout runs and page
     * reads have no purpose.
     */
    public const TASTE_PROFILE = 'taste_profile';

    protected function casts(): array
    {
        return [
            'cost' => 'float',
        ];
    }

    /**
     * Record what a search's agent run used, where it searched when that isn't the search's own location (a scan's
     * run in one of the other places), and the page it read, for a read of one.
     */
    public static function record(Search $search, AgentResponse $response, ?string $location = null, ?Source $source = null): self
    {
        return static::query()->create([
            'user_id' => $search->user_id,
            'search_id' => $search->id,
            'source_id' => $source?->id,
            'location' => $location ?? $search->location,
            ...static::measure($response),
        ]);
    }

    /**
     * Record what writing a family's taste profile used. It belongs to the family rather than to any one search.
     */
    public static function recordTasteProfile(User $user, AgentResponse $response): self
    {
        return static::query()->create([
            'user_id' => $user->id,
            'purpose' => self::TASTE_PROFILE,
            'location' => $user->home_location,
            ...static::measure($response),
        ]);
    }

    /**
     * The provider, model, tokens, web searches, pages fetched and cost of a run. Each step is one request to the
     * provider, so each is priced on its own.
     *
     * @return array<string, mixed>
     */
    protected static function measure(AgentResponse $response): array
    {
        $model = (string) $response->meta->model;
        $costs = [];
        $webSearches = 0;
        $pagesFetched = 0;

        foreach ($response->steps as $step) {
            $searches = static::webSearches($step);
            $webSearches += $searches;
            $pagesFetched += static::pagesFetched($step);
            $costs[] = Pricing::cost($model, $step->usage, $searches);
        }

        $cost = in_array(null, $costs, true) ? null : array_sum($costs);

        return [
            'provider' => (string) $response->meta->provider,
            'model' => $model,
            'input_tokens' => $response->usage->inputTokens,
            'cache_write_tokens' => $response->usage->cacheWriteInputTokens ?? 0,
            'cache_read_tokens' => $response->usage->cacheReadInputTokens ?? 0,
            'output_tokens' => $response->usage->outputTokens,
            'web_searches' => $webSearches,
            'pages_fetched' => $pagesFetched,
            'cost' => $cost,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Search, $this>
     */
    public function search(): BelongsTo
    {
        return $this->belongsTo(Search::class);
    }

    /**
     * The page this usage read, for Sunshine's own reads; null for a scout's run.
     *
     * @return BelongsTo<Source, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    /**
     * The events this run found and kept.
     *
     * @return HasMany<Event, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    protected static function webSearches(Step $step): int
    {
        return collect($step->providerToolCalls)
            ->filter(fn (ProviderToolCall $call): bool => $call->type === 'server_tool_use' && ($call->data['name'] ?? null) === 'web_search')
            ->count();
    }

    /**
     * Pages that came back from a fetch. Failed fetches put nothing in the context, so they are left out.
     */
    protected static function pagesFetched(Step $step): int
    {
        return collect($step->providerToolCalls)
            ->filter(fn (ProviderToolCall $call): bool => $call->type === 'web_fetch_tool_result' && ($call->data['content']['type'] ?? null) === 'web_fetch_result')
            ->count();
    }
}
