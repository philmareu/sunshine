<?php

namespace App\Ai\Agents;

use App\Enums\EventStatus;
use App\Models\ActivityNote;
use App\Models\Event;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Laravel\Ai\Providers\Tools\ProviderTool;
use Laravel\Ai\Providers\Tools\WebFetch;
use Laravel\Ai\Providers\Tools\WebSearch;
use Stringable;

/**
 * Searches the web for real events and picks the ones this user is likely to enjoy, using their stated preferences,
 * what they made of past suggestions, how the things they went to turned out and their notes on them.
 */
#[Timeout(300)]
class EventScout implements Agent, HasProviderOptions, HasStructuredOutput, HasTools
{
    use Promptable;

    /**
     * How many marked events of each kind the scout learns from: suggestions they marked, and things they went to.
     */
    public const FEEDBACK_LIMIT = 40;

    /**
     * The most of any one page the scout reads, in tokens. Longer pages are cut off, so one huge page can't flood a run.
     */
    public const MAX_PAGE_TOKENS = 20_000;

    /**
     * The most pages one run fetches, watched pages included.
     */
    public const MAX_PAGES_FETCHED = 10;

    /**
     * The most web searches one run makes. A run with watched pages to read already has much of what it needs, so
     * each page takes one search away, down to MIN_WEB_SEARCHES.
     */
    public const MAX_WEB_SEARCHES = 10;

    public const MIN_WEB_SEARCHES = 4;

    /**
     * The most events one run returns. Good events left out for space this week aren't known to next week's scan,
     * so this errs on the generous side; each one costs a few hundred output tokens.
     */
    public const MAX_EVENTS = 25;

    /**
     * Anthropic's web tools that filter what they find with code before it reaches the context, which is where most
     * of a run's tokens go. The SDK sends older versions unless told otherwise.
     */
    public const WEB_SEARCH_TOOL = 'web_search_20260318';

    public const WEB_FETCH_TOOL = 'web_fetch_20260318';

    /**
     * @param  ?string  $location  Where this run searches, to localize its web searches.
     * @param  int  $pagesToCheck  How many pages the brief asks it to check.
     */
    public function __construct(public User $user, public ?string $location = null, public int $pagesToCheck = 0) {}

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        $maxEvents = self::MAX_EVENTS;

        return <<<MD
        You help a family find things to do. Search the web for real, upcoming events: town and city event calendars, university athletics and arts, libraries, parks and recreation, museums, venues, and community classes. Look across neighbouring towns too.

        Rules:
        - Search the web before you answer, every time. Pages they ask you to check, and events listed for you from them, cover only part of what's on.
        - Only return events you found on a web page during this search, with that page's URL. Never invent or guess an event, date or URL.
        - Only return events whose date falls inside the requested range. Skip events whose date you could not confirm.
        - Return up to {$maxEvents} events, the best matches first.
        - Include two or three wildcards: events outside their stated interests that they might still enjoy. Mark them as wildcards.
        - Never return anything that matches what they want to avoid, or that resembles events they reacted badly to.
        - In each reason, say in one sentence why this family might enjoy it, pointing to what you know about them when you can.

        {$this->knowledge()}
        MD;
    }

    /**
     * Get the tools available to the agent.
     *
     * @return list<Agent|Tool|ProviderTool>
     */
    public function tools(): iterable
    {
        $search = (new WebSearch)
            ->max(self::webSearchesFor($this->pagesToCheck))
            ->withProviderOptions(fn (Lab|string $provider): array => $provider === Lab::Anthropic ? ['type' => self::WEB_SEARCH_TOOL] : []);

        if (filled($this->location)) {
            // A place is written like "Riverton, OR": the town, then the state or region.
            [$city, $region] = array_pad(array_map(trim(...), explode(',', $this->location, 2)), 2, null);

            $search->location(city: $city, region: $region ?: null);
        }

        return [
            $search,
            (new WebFetch)->max(self::MAX_PAGES_FETCHED)->withProviderOptions(fn (Lab|string $provider): array => $provider === Lab::Anthropic
                ? ['type' => self::WEB_FETCH_TOOL, 'max_content_tokens' => self::MAX_PAGE_TOKENS]
                : []),
        ];
    }

    /**
     * How many web searches a run gets when it has this many pages to check.
     */
    public static function webSearchesFor(int $pagesToCheck): int
    {
        return max(self::MIN_WEB_SEARCHES, self::MAX_WEB_SEARCHES - $pagesToCheck);
    }

    /**
     * Turn on Anthropic's prompt caching: a run is one long loop of searches and fetches that re-reads everything
     * found so far on every step, and cached, those re-reads cost a twentieth of the price. Run at medium effort,
     * which Anthropic recommends for well-specified work with many tool calls; it searches and writes more tersely.
     *
     * @return array<string, mixed>
     */
    public function providerOptions(Lab|string $provider): array
    {
        return $provider === Lab::Anthropic
            ? ['cache_control' => ['type' => 'ephemeral'], 'output_config' => ['effort' => 'medium']]
            : [];
    }

    /**
     * Get the agent's structured output schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'events' => $schema->array()->required()->items($schema->object([
                'title' => $schema->string()->required(),
                'description' => $schema->string()->required()->description('Two or three sentences on what it is.'),
                'date' => $schema->string()->required()->description('The date it happens, as YYYY-MM-DD.'),
                'time' => $schema->string()->nullable()->required()->description('Start time as written on the page, e.g. "7:00 PM".'),
                'start_time' => $schema->string()->nullable()->required()->description('The start time in 24-hour HH:MM, e.g. "19:00", or null if the page doesn\'t give one.'),
                'end_time' => $schema->string()->nullable()->required()->description('The end time in 24-hour HH:MM, e.g. "21:30", or null if the page doesn\'t give one.'),
                'venue' => $schema->string()->nullable()->required(),
                'city' => $schema->string()->nullable()->required()->description('The town or city it is in.'),
                'region' => $schema->string()->nullable()->required()->description('The state, province or region, written out in full, e.g. "Oregon" or "Ontario".'),
                'country' => $schema->string()->nullable()->required()->description('Two-letter country code, e.g. "US" or "CA".'),
                'category' => $schema->string()->required()->description('A short label such as Sports, Music, Class, Outdoors or Festival.'),
                'cost' => $schema->string()->nullable()->required()->description('Price as written on the page, or "Free".'),
                'url' => $schema->string()->required()->description('The page you found it on.'),
                'reason' => $schema->string()->required(),
                'is_wildcard' => $schema->boolean()->required(),
                'seen_before' => $schema->integer()->nullable()->required()->description('If this is one of the numbered things from what you know about them coming around again, its number; otherwise null.'),
            ])),
            'sources' => $schema->array()->required()->description('One entry for each page they asked you to check; empty if they listed none.')->items($schema->object([
                'url' => $schema->string()->required()->description('The page\'s address, exactly as they gave it.'),
                'note' => $schema->string()->required()->description('One or two sentences: what you found there in the date range, and why you left any of it out.'),
            ])),
        ];
    }

    /**
     * What the scout knows about this user: their preferences, the weekly summary of their taste (TasteProfile), what
     * they made of recent suggestions and how the things they went to turned out. The summary carries their taste;
     * the numbered lists are for knowing a thing again when it comes around, with what they said about it.
     */
    protected function knowledge(): string
    {
        $lines = ['What you know about them:'];

        $lines[] = filled($this->user->interests)
            ? '- They are interested in: '.implode(', ', $this->user->interests)
            : '- They have not said what they are interested in yet.';

        if (filled($this->user->avoid)) {
            $lines[] = '- They want to avoid: '.implode(', ', $this->user->avoid);
        }

        if (filled($this->user->budget)) {
            $lines[] = '- Their budget: '.$this->user->budget;
        }

        $profile = $this->user->taste_profile;

        if (filled($profile)) {
            $lines[] = '';
            $lines[] = 'How their taste runs, summed up from everything they have marked and the suggestions they let pass. Find more of what they say yes to, and steer away from what they skip:';
            $lines[] = $profile;
        }

        $marked = $this->marked([EventStatus::Interested, EventStatus::NotForUs]);

        if ($marked->isNotEmpty()) {
            $lines[] = '';
            $lines[] = filled($profile)
                ? 'Suggestions they marked recently, newest first, so you know them when they come around again. When one does, give its number as seen_before.'
                : 'What they made of past suggestions, newest first. Find more like the ones they were interested in, and steer away from the ones that were not for them. When one of these comes around again, give its number as seen_before.';

            foreach ($marked as $event) {
                $lines[] = sprintf('- #%d, %s: "%s" (%s)', $event->activity_id, $event->status->getLabel(), $event->title, $event->category ?? 'uncategorised')
                    .(filled($event->note) ? ' — '.$event->note : '');
            }
        }

        $went = $this->marked([EventStatus::Went]);

        if ($went->isNotEmpty()) {
            $lines[] = '';
            $lines[] = 'Things they went to, newest first, with how it was. Find more like the ones they enjoyed. When one of these comes around again, say so in its reason, use what they said about it, and give its number as seen_before.';

            foreach ($went as $event) {
                $lines[] = sprintf('- #%d, %s: "%s" (%s)', $event->activity_id, $event->starts_on->toDateString(), $event->title, $event->category ?? 'uncategorised')
                    .($event->rating ? ", rated {$event->rating}/5" : '')
                    .(filled($event->retrospective) ? ' — '.$event->retrospective : '');
            }
        }

        $notes = ActivityNote::query()
            ->whereIn('activity_id', $marked->pluck('activity_id')->merge($went->pluck('activity_id'))->unique())
            ->oldest('id')
            ->get()
            ->groupBy('activity_id');

        if ($notes->isNotEmpty()) {
            $lines[] = '';
            $lines[] = 'Their own notes on some of these, by number. Keep them in mind, and when one of these comes around again, pass on anything useful from its notes in its reason.';

            foreach ($notes as $activityId => $activityNotes) {
                $lines[] = "- #{$activityId}: ".$activityNotes->map(fn (ActivityNote $note): string => Str::squish($note->body))->implode(' / ');
            }
        }

        $avoided = $this->user->activities()->whereNotNull('avoided_at')->latest('avoided_at')->limit(self::FEEDBACK_LIMIT)->get();

        if ($avoided->isNotEmpty()) {
            $lines[] = '';
            $lines[] = 'Things they never want suggested again. Leave these out, and steer away from things like them.';

            foreach ($avoided as $activity) {
                $lines[] = sprintf('- "%s"', $activity->title)
                    .(filled($activity->city) ? " in {$activity->city}" : '')
                    .(filled($activity->avoid_reason) ? ' — '.$activity->avoid_reason : '');
            }
        }

        if ($marked->isEmpty() && $went->isEmpty()) {
            $lines[] = '- They have not marked any suggestions yet.';
        }

        return implode("\n", $lines);
    }

    /**
     * The events they most recently marked with one of these statuses.
     *
     * @param  list<EventStatus>  $statuses
     * @return Collection<int, Event>
     */
    protected function marked(array $statuses): Collection
    {
        return $this->user->events()
            ->whereIn('events.status', $statuses)
            ->latest('events.marked_at')
            ->limit(self::FEEDBACK_LIMIT)
            ->get();
    }
}
