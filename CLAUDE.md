# Sunshine

Finds things to do near home. Its main job is a weekly scan: for each user with a home location, an AI agent searches the web near home, then each other place they'd go in a run of its own (each run also fetches the pages the user watches for that place), for events in the next 21 days, on the days the user is free, and adds the new ones to Upcoming, the home page. It runs on the user's landing day, and "Check now" runs one any time. Its second job is ad hoc searches for any place and dates (travel, or something specific). Each event is one time an activity came up (the farmers market, a team's home games), and each activity gathers every date it was found on, along with the family's notes, photos and a checklist for every visit. An activity can be avoided (never suggested again), merged with its duplicates, and a wrongly linked date can be moved out. Each event has a life the family marks as it goes: Interested (it moves from Upcoming to Plans) or Not for us (which can mark the same thing's other dates to come, and ones like it, along with it) beforehand, then Went (with a rating and a retrospective, kept in History) or Didn't go. Beforehand they can also say they can't make that date, which takes it off Upcoming and Plans without changing its mark. Every later run learns from those marks, and when it finds something again, it adds the new event to that activity. Every AI run records its tokens, web searches, pages fetched and estimated cost.

This is a deliberately lean build: add features one at a time as real use shows the need.

## Stack

Laravel 13, Filament 5 (the whole UI), the Laravel AI SDK (`laravel/ai`) on Anthropic with its web search and web fetch tools, Laravel MCP with Sanctum tokens, MySQL, Pest, and Playwright's headless Chromium (`browser/`, its own `package.json`) for pages that fill in their events with JavaScript.

## Where things are

| Where | What |
|---|---|
| `app/Ai/Agents/EventScout.php` | The agent: instructions, what it knows about the user (preferences, budget, the weekly summary of their taste, the last 40 suggestions they marked and the last 40 things they went to, numbered by activity so it can name one coming around again as `seen_before`, their notes on those activities, and the activities they avoid), web search and fetch tools (fetched pages are cut off at `MAX_PAGE_TOKENS`), structured output schema. It asks for Anthropic's newer web tool versions (`WEB_SEARCH_TOOL`, `WEB_FETCH_TOOL`), which filter what they find with code before it reaches the context; the SDK sends older ones unless told. Web searches are localized to the run's place, and a run makes fewer of them the more pages it has to check. Turns on Anthropic prompt caching (a run re-reads everything it has found on every step, and cached re-reads cost a twentieth) and runs at medium effort. |
| `app/Ai/PageRead.php`, `app/Ai/Agents/PageReader.php` | Sunshine's own read of a page a run opens, in place of the scout's fetch: got from Sunshine's server through `PageCheck::get()` (public addresses only, robots.txt followed as for Anthropic's fetcher on every address it redirects to), boiled down to text (HTML keeps each link's address the first time it comes up; feeds lose their tags; iCal, JSON and text as they are), and listed by `PageReader` on a cheap model (`sunshine.pages.reader_model`, Haiku): the page's dated events in the range, at times the user can go out on a scan, at most `MAX_EVENTS`. Each read records its own usage, with its source. A page Sunshine can't read (an error, a PDF, robots.txt, nearly no text, a reader that fails or runs out of room) is left to the scout's fetch. A page with one address for each day (a `Source` whose address has `{date}`, or `{date:FORMAT}` in PHP's date format letters) is read once for each of the run's days (`Search::daysToRead()`: each day of an ad hoc search, or each day of a scan the user is free, at most `Source::MAX_DAYS`), its events linked to their day's page, and the reads are merged into one (`PageRead::ofDays()`); when Sunshine can't read any of them, the scout is asked to fetch its first `Source::DAYS_TO_FETCH` days. |
| `app/Ai/TasteProfile.php`, `app/Ai/Agents/TasteProfiler.php` | The family's taste, summed up in a few bullet points (`User::taste_profile`) by `TasteProfiler` on a cheap model (`sunshine.taste.model`, Haiku) from the last 200 events they marked Interested, Not for us or Went, with their notes, ratings and retrospectives, and the last 200 they left unmarked for `SOFT_NO_AFTER_DAYS` (soft no's; Didn't go and can't-make-it events are left out), each with its weekday, time, category, city and whether it was a wildcard. A scan's run near home writes a new one first when the last is `MAX_AGE_DAYS` old, if they have marked anything; a failure keeps the old one. Its usage belongs to the user, not a search, with `purpose` `taste_profile`. With a summary, the scout's numbered lists are for knowing a thing again rather than for learning their taste. |
| `app/Ai/PageBrowser.php`, `browser/render.mjs` | Sunshine's browser, for a `Source` that `needs_browser`: `PageRead` first fetches the page as usual, which follows its redirects with robots.txt and the public-address guard on each, then shows the address it ends at in headless Chromium and reads what that shows (`PageRead::shown()`). The Node script reaches the web only through its own proxy, which connects to public addresses on ports 80 and 443, pinned to the one it checked; it opens no other page (other navigations and frames are refused), skips images, media and fonts, waits until the page's text stops growing, and says it is HeadlessChrome and Sunshine. One page is shown at a time, under a cache lock. |
| `app/Jobs/RunSearch.php` | Runs the agent for a search, records usage along with the place that run searched, saves events inside the date range. Before the scout, it reads the run's pages itself (`PageRead`), `PAGES_AT_ONCE` at a time with Laravel's `Concurrency` until `READING_TIMEOUT` is up (pages with one address for each day go last, so their last days are what's left out), each in a process of its own that leaves its read in the cache as plain data (the cache unserializes no classes) rather than handing it back, since handing results back through printed output broke on the server; anything a read prints is logged. It then lists their events in the brief, leaving out the ones the run wouldn't keep anyway (outside the dates, at times the user isn't free, already found); the scout fetches only the pages Sunshine couldn't read. `READ_PAGES=false` leaves every page to the scout. A scan runs near home first, then dispatches itself once for each of `User::otherPlaces()` in turn, so each run skips what earlier ones found. Each run opens only its own pages (`Search::sourcesFor()`): an ad hoc search's own, or a scan run's place's watched pages; only the last marks the scan Completed, and a failed run stops the rest. Each scan run's brief names the places the other runs cover, so it stays near its own. Scans skip events already found on the same date (`Event::isRepeat()`): with the same title, or in the same city under other words, when one title's words all appear in the other's (at least two of them, leaving out punctuation, years and filler words), since the scout rewords what it finds again. They also skip events at times the user isn't free (`User::isFreeAt()`: on a day they're only free in the evening, an event has to start or still be going from 5 PM). Each event joins an activity: the one the scout named as `seen_before` if it is this user's, or else the one with the same title in the same city (`Activity::key`), or a new one. Events of avoided activities are dropped. |
| `app/Console/Commands/DiscoverEvents.php` | `events:discover`, run hourly from `routes/console.php`: starts the weekly scan for each user with a home location once their landing day has begun where they live, unless a scan has started since then. |
| `app/Models` | `User` (home_location, places as a JSON list, landing_day, free_all_day and free_evenings as days of the week numbered 0 for Sunday to 6, interests, avoid, budget), `Search` (`kind` is `ad_hoc` or `discovery`; a discovery search is a "scan"; one holding marked events can't be deleted, because it is the family's history), `Activity` (one per thing to do; `visits` are its Went events; `notes`, `photos` and `checklistItems`; `avoided_at`; `absorb()` merges others into it; deleting a search removes the activities it leaves `unused`), `ActivityNote`, `ActivityPhoto` (files on the private disk in `sunshine.photos.disk`, shown through temporary URLs), `ChecklistItem`, `Event` (one time an activity came up; `hidden_at` when they can't make it, which hides it from Upcoming (a default filter) and Plans but leaves `status` alone, so the scout learns nothing from it; `usage` is the AI run that found it, null for events found before runs were recorded on them; `status` is an `EventStatus`; city, region and country; `previousVisit()` is the activity's last Went event before it; `checked_items` are the checklist items ticked off for this visit; `moveTo()` moves it to another or a new activity), `Source` (a page to check: a watched page, opened by every scan in the run for its `place`, where null or a place the user no longer lists means near home; or one ad hoc search's page, with `search_id`; after each run, whether the page was read, by Sunshine or by the scout's fetch, how many events Sunshine read on it (`events_read`, null when it was left to the scout) with its reader's note or why it couldn't read it (`read_note`), and the scout's note on it), `AiUsage` (a scout's run, with `source` Sunshine's read of a page, or with `purpose` `taste_profile` and no search, the weekly taste summary). |
| `app/Mcp`, `routes/ai.php`, `Pages/Claude` | The MCP server at `/mcp` for Claude Code. It acts as the user whose Sanctum token it is, made on the Connect Claude page, and covers preferences, sources and ad hoc searches, and reads scans: `get-scan` gives a scan run by run, with each run's usage and the events it kept, and `list-upcoming` gives what Upcoming shows, across scans. `check-source` runs `app/Ai/PageCheck.php`, which checks from Sunshine's server, where scans read pages too, what a scan will make of a page: content type, robots.txt, size, and how far ahead its dates reach. It only connects to public addresses, and it checks each redirect and pins the address it checked. |
| `app/Ai/Pricing.php`, `config/sunshine.php` | Cost per request from per-model rates. Update the rates when Anthropic's pricing changes; unknown models are tracked without a cost. |
| `app/Filament` | `Pages/Upcoming` (home, at `/`; default filters hide what they have marked Interested or Not for us, avoided, or can't make, since it is for finding new things; "Busy days" picks days ahead they can't make and hides everything on them, the scans' events and plans from ad hoc searches, as "Can't make it" does), `Pages/Plans` (Interested, across all searches; past ones ask "did you go?", and can be picked a day at a time, or all at once, to mark Didn't go together), `Pages/History` (Went, newest first), `Resources/Activities` (every activity; its page has tabs for every time it came up, notes, photos and the checklist, and actions to avoid or merge it), `Resources/Searches` (ad hoc only; pages to check for one search are added when it is created; each search's events have a tab per status), `Resources/Sources`, `Pages/Preferences`, `Resources/AiUsages` (Usage). `Tables/EventsTable` is the shared event-card table with the status actions, "Can't make it" (a second click shows it again), "Interested, but can't make it" (both in one click, until it is Interested) ("Not for us" can also mark `Event::relatedToCome()`, the same thing's other unmarked dates to come and events in the same city whose titles differ only in their numbers, and avoid the activity), the checklist, a link to the activity, and area filters. |

## Time

The app timezone stays UTC, and timestamps are stored in UTC. Each user has a `timezone` (set in Preferences): `User::today()` is their date, which decides scan windows, what Upcoming hides as past, and when their weekly scan starts. Show clock times in the user's timezone column by column (`->timezone(...)`), not with `FilamentTimezone`, because that would also shift date-only values like event dates back a day.

## Running it

- Serve the site however you like (Herd, Valet, `php artisan serve`). `php artisan db:seed` makes `test@example.com` with the password `password`.
- Keep `composer run dev` running: the queue worker (searches run there), the scheduler (weekly scan) and the log tail. The site is served separately, and Filament ships its own assets, so `serve` and Vite are left out in `AppServiceProvider`.
- `.env`: `ANTHROPIC_API_KEY`, `ANTHROPIC_MODEL` (default `claude-sonnet-5-5`), `PAGE_READER_MODEL` (default `claude-haiku-5-5`), `TASTE_MODEL` (default `claude-haiku-5-5`), `READ_PAGES` (default true), `DISCOVERY_DAYS_AHEAD` (default 21), `PHOTOS_DISK` (default `local`, which is private), `BROWSER_NODE` (default `node`, the Node binary for Sunshine's browser). `AI_PROVIDER` switches to another provider that supports web search.
- Sunshine's browser needs, once: `cd browser && npm ci && npx playwright install chromium`. A deploy script should run `(cd browser && npm ci && npx playwright install chromium)`, a no-op once that Chromium is in `~/.cache/ms-playwright`; a new server also needs `sudo npx playwright install-deps chromium` once.
- `php artisan test --compact` while working. Tests fake the agents with `EventScout::fake()`, `PageReader::fake()` and `TasteProfiler::fake()`, and the browser with `Process::fake()` on `render.mjs`. No test reaches the web: `tests/TestCase.php` refuses requests without a fake and resolves hosts without DNS, and `phpunit.xml` runs `Concurrency` in-process (`CONCURRENCY_DRIVER=sync`) so the fakes reach the reads.

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

## Foundational Context

This application is a Laravel application running on PHP 8.5. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If a frontend change doesn't show in the UI or you get a "Unable to locate file in Vite manifest" error, run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists, including path-scoped framework guidelines under `.ai/rules/boost`. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.
- Activate the `deploying-to-cloud` skill whenever deploying to Laravel Cloud, configuring Cloud environments or resources, using the Cloud CLI, or troubleshooting Cloud deployments.

=== tests rules ===

# Test Enforcement

- Add or update tests for behavior and logic changes when a test provides meaningful regression coverage.
- Pure copy, styling, and layout-only changes do not require new or updated tests.
- When test coverage applies, run the affected tests and ensure they pass.
- Test the changed behavior and its important failure modes, but do not add tests beyond them.
- Read the `testing-best-practices` skill before writing tests.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

# Pest

- This project uses Pest. Create tests with `php artisan make:test --pest {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.
- Do not delete tests or test files without approval. They are part of the application.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/pest` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.

</laravel-boost-guidelines>
