<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\SearchKind;
use App\Enums\SearchStatus;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable([
    'name', 'email', 'password', 'home_location', 'places', 'timezone', 'landing_day', 'free_all_day', 'free_evenings',
    'interests', 'avoid', 'budget',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * When the evening starts on a day they're only free in the evening, as 24-hour "HH:MM".
     */
    public const EVENING_STARTS = '17:00';

    protected $attributes = [
        'timezone' => 'UTC',
        'landing_day' => CarbonInterface::THURSDAY,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'landing_day' => 'integer',
            'free_all_day' => 'array',
            'free_evenings' => 'array',
            'places' => 'array',
            'interests' => 'array',
            'avoid' => 'array',
            'taste_profiled_at' => 'datetime',
        ];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }

    /**
     * @return HasMany<Activity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class);
    }

    /**
     * @return HasMany<Search, $this>
     */
    public function searches(): HasMany
    {
        return $this->hasMany(Search::class);
    }

    /**
     * @return HasManyThrough<Event, Search, $this>
     */
    public function events(): HasManyThrough
    {
        return $this->hasManyThrough(Event::class, Search::class);
    }

    /**
     * Events found by scans of the home area: what Upcoming shows.
     *
     * @return HasManyThrough<Event, Search, $this>
     */
    public function discoveredEvents(): HasManyThrough
    {
        return $this->events()->where('searches.kind', SearchKind::Discovery);
    }

    /**
     * @return HasMany<Source, $this>
     */
    public function sources(): HasMany
    {
        return $this->hasMany(Source::class);
    }

    /**
     * The pages every scan opens, each in the run for its place: their sources that aren't for one search.
     *
     * @return HasMany<Source, $this>
     */
    public function watchedSources(): HasMany
    {
        return $this->sources()->whereNull('search_id');
    }

    /**
     * @return HasMany<AiUsage, $this>
     */
    public function aiUsages(): HasMany
    {
        return $this->hasMany(AiUsage::class);
    }

    /**
     * The date where the user lives. The app runs in UTC; this decides what "today" means for them.
     */
    public function today(): CarbonImmutable
    {
        return CarbonImmutable::today($this->timezone);
    }

    /**
     * The most recent landing day where the user lives: today if today is the day.
     */
    public function lastLandingDay(): CarbonImmutable
    {
        $today = $this->today();

        return $today->subDays(($today->dayOfWeek - $this->landing_day + 7) % 7);
    }

    /**
     * Whether the weekly scan is due: no scan has started since the last landing day began.
     * A scan started earlier in the week with "Check now" does not count.
     */
    public function scanDue(): bool
    {
        return ! ($this->latestScan()?->created_at->gte($this->lastLandingDay()) ?? false);
    }

    /**
     * Whether they could go to something on this date that runs between these times, given as 24-hour "HH:MM". On a
     * day they're only free in the evening, it has to start in the evening or still be going then; one without
     * times counts, as its times can't be checked. Every day counts until they pick some.
     */
    public function isFreeAt(CarbonInterface $date, ?string $startTime = null, ?string $endTime = null): bool
    {
        $allDay = array_map(intval(...), $this->free_all_day ?? []);
        $evenings = array_map(intval(...), $this->free_evenings ?? []);

        if ($allDay === [] && $evenings === []) {
            return true;
        }

        if (in_array($date->dayOfWeek, $allDay, true)) {
            return true;
        }

        if (! in_array($date->dayOfWeek, $evenings, true)) {
            return false;
        }

        if ($startTime === null && $endTime === null) {
            return true;
        }

        return ($startTime !== null && $startTime >= self::EVENING_STARTS)
            || ($endTime !== null && $endTime > self::EVENING_STARTS);
    }

    /**
     * The places they'd go besides home. Every scan looks in each one in a run of its own.
     *
     * @return list<array{location: string, note?: ?string}>
     */
    public function otherPlaces(): array
    {
        return collect($this->places ?? [])->filter(fn (array $place): bool => filled($place['location'] ?? null))->values()->all();
    }

    public function latestScan(): ?Search
    {
        return $this->searches()->where('kind', SearchKind::Discovery)->latest('id')->first();
    }

    public function isScanning(): bool
    {
        return $this->searches()
            ->where('kind', SearchKind::Discovery)
            ->whereIn('status', [SearchStatus::Pending, SearchStatus::Running])
            ->exists();
    }
}
