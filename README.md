# Sunshine

Sunshine finds things for a family to do near home.

Once a week, an AI agent (Anthropic's Claude, through the [Laravel AI SDK](https://github.com/laravel/ai), with web search and web fetch) looks for events in the next three weeks near your home and any other places you go, on the days you are free. New finds land on Upcoming, the home page. You can also run an ad hoc search for any place and dates, for a trip or something specific.

You mark what you make of each event, and every later run reads those marks:

- **Interested** moves it to Plans.
- **Not for us** drops it. It can also drop the same thing's other dates and ones like it.
- **Went** keeps it in History, with a rating and a few notes on how it went.
- **Didn't go** closes it out.
- **Can't make it** hides it without saying anything about whether you like it.

## Project status and support

Sunshine is a personal project, shared as-is. It is built for one family's use and changes when that use calls for it.

- There is no support. Issues and pull requests may not get a reply soon, or at all.
- There is no roadmap and no promise that anything will be fixed, added or merged.
- Forks are welcome. If you want it to work differently, forking is the reliable way to get there.

## Features

- A weekly scan on the day you pick, plus "Check now" to run one any time.
- Ad hoc searches for any place and date range.
- Watched pages (Sources): event calendars and venue pages that each scan reads. Pages that only fill in their events with JavaScript can be opened in a headless browser.
- Activities: each thing to do gathers every date it was found on, with notes, photos and a checklist. You can avoid an activity, so it is never suggested again, or merge duplicates.
- A short summary of the family's taste, rewritten about once a week from what you marked.
- A Usage page with the tokens, web searches, pages fetched and estimated cost of every AI run.
- An MCP server, so Claude Code can read your preferences, manage watched pages and run searches for you.

The whole interface is a [Filament](https://filamentphp.com) panel.

## Costs

Every scan and search calls the Anthropic API, with web search and web fetch. That costs real money on your Anthropic account each time it runs. A weekly scan runs once near home and once more for each other place you list.

Sunshine records the tokens, web searches, pages fetched and estimated cost of each run on the Usage page. Costs come from the per-model rates in `config/sunshine.php`. Check those against Anthropic's current pricing. A model that isn't listed there is tracked without a cost.

What affects the cost:

- `ANTHROPIC_MODEL`: the model the main agent (the scout) runs on. This is most of the cost.
- `PAGE_READER_MODEL` and `TASTE_MODEL`: cheaper models for reading pages and summing up taste.
- `READ_PAGES`: when true (the default), Sunshine fetches watched pages itself and has the cheap reader model list their events, so the scout doesn't fetch them. When false, the scout fetches every page.
- `DISCOVERY_DAYS_AHEAD`: how far ahead each scan looks (default 21 days).
- How many other places you list in Preferences. Each one is a run of its own.
- How many watched pages you add. Each run reads the pages for its place.
- How often you press "Check now" or run ad hoc searches.

Set a spending limit on your Anthropic account.

## Requirements

- PHP 8.4 or newer, with the extensions Laravel needs, plus `pcntl` (so the queue worker can stop jobs that run too long) and `proc_open` (pages are read in parallel processes).
- Composer.
- Node.js 20.19 or newer, for Sunshine's browser (`browser/`, Playwright with headless Chromium).
- MySQL. Sunshine is developed and run on MySQL. The test suite runs on SQLite, but real use on SQLite is untested.
- A queue worker. Every scan and search runs on the queue.
- The scheduler, run by cron every minute. It starts the weekly scans.
- An Anthropic API key.

The interface uses Filament's own built assets, so no front-end build step is needed to run it.

## Self-hosting

### 1. Get the code and dependencies

```bash
git clone https://github.com/philmareu/sunshine.git
cd sunshine
composer install --no-dev --optimize-autoloader
```

Use `--no-dev` in production. The dev dependencies are only for development and testing.

### 2. Configure the environment

```bash
cp .env.example .env
php artisan key:generate
```

Edit `.env`. Set at least:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://sunshine.example.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=sunshine
DB_USERNAME=sunshine
DB_PASSWORD=your-password

ANTHROPIC_API_KEY=your-key
```

Sunshine's own settings, from `config/sunshine.php` and `config/ai.php`:

| Variable | Default | What it does |
|---|---|---|
| `ANTHROPIC_API_KEY` | (none) | Your Anthropic API key. Required. |
| `ANTHROPIC_MODEL` | `claude-sonnet-5-5` | The model the scout runs on. |
| `PAGE_READER_MODEL` | `claude-haiku-5-5` | The cheap model that lists the events on each page Sunshine reads itself. |
| `TASTE_MODEL` | `claude-haiku-5-5` | The cheap model that writes the weekly taste summary. |
| `READ_PAGES` | `true` | Read watched pages with the cheap model before the scout runs. `false` leaves every page to the scout's fetch. |
| `DISCOVERY_DAYS_AHEAD` | `21` | How many days ahead the weekly scan looks. |
| `PHOTOS_DISK` | `local` | The filesystem disk for activity photos. Keep it private. |
| `BROWSER_NODE` | `node` | The Node binary that runs `browser/render.mjs`. Set a full path if `node` isn't on the worker's `PATH`. |
| `AI_PROVIDER` | `anthropic` | The AI provider. Sunshine is built for Anthropic: the web tool versions, prompt caching and cost tracking are Anthropic-specific, and the reader and taste models default to Anthropic models. Other providers are untested. |
| `ANTHROPIC_URL` | `https://api.anthropic.com/v1` | The Anthropic API base URL. |

Queue setting worth knowing: `DB_QUEUE_RETRY_AFTER` (default `500` in `.env.example`) must stay above the search job's 450-second timeout (`App\Jobs\RunSearch::$timeout`). If it's lower, a long search can be picked up a second time while it is still running, and fail.

The rest of `.env` is standard Laravel. Sessions, cache and the queue use the database by default, so nothing beyond MySQL is needed. Mail isn't used.

### 3. Set up the database

```bash
php artisan migrate --force
```

### 4. Set up Sunshine's browser

Some event pages only fill in their events with JavaScript. Sunshine opens those in headless Chromium.

```bash
cd browser
npm ci
npx playwright install chromium
cd ..
```

On a fresh Linux server, also install Chromium's system libraries once:

```bash
cd browser && sudo npx playwright install-deps chromium
```

Run `npx playwright install chromium` as the same user the queue worker runs as. Playwright keeps Chromium in that user's `~/.cache/ms-playwright`. If you put it somewhere else, set `PLAYWRIGHT_BROWSERS_PATH` for the worker.

### 5. Create the first user

There is no sign-up page. Create users from the command line:

```bash
php artisan make:filament-user
```

It asks for a name, email and password. Every user can sign in and has their own preferences, scans and history. There is no password reset by email and no profile page, so use the same command (or `php artisan tinker`) to manage accounts.

### 6. Run the queue worker

Scans and searches run on the queue. A search can take several minutes. Run a worker under Supervisor or systemd so it restarts if it stops. A Supervisor example:

```ini
[program:sunshine-worker]
command=php /var/www/sunshine/artisan queue:work --sleep=3 --tries=1
directory=/var/www/sunshine
user=www-data
autostart=true
autorestart=true
stopwaitsecs=500
redirect_stderr=true
stdout_logfile=/var/www/sunshine/storage/logs/worker.log
```

`stopwaitsecs` gives a running search time to finish when the worker is stopped. After each deploy, run `php artisan queue:restart` so the worker picks up the new code.

### 7. Run the scheduler

Add one cron entry for the user the app runs as:

```cron
* * * * * cd /var/www/sunshine && php artisan schedule:run >> /dev/null 2>&1
```

The scheduler runs `events:discover` every hour. It starts each user's weekly scan once their landing day has begun in their timezone, unless a scan has already started since then.

### 8. Serve it

Point your web server (Nginx, Caddy, Apache) at the `public` directory, the same as any Laravel app. Serve it over HTTPS. Then cache the configuration:

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan filament:optimize
```

Run `php artisan config:clear` (or `config:cache` again) after changing `.env`.

### Photo storage

Activity photos are family photos, so they go on a private disk and are shown through links that expire after 30 minutes. The default `local` disk keeps them in `storage/app/private` and serves those links itself. Back that directory up along with the database.

To use S3 or a compatible store, install `league/flysystem-aws-s3-v3`, set the `AWS_*` variables, and set `PHOTOS_DISK=s3`. Keep the bucket private.

## First run

Sign in, then:

1. **Preferences.** Set your home location (a town or neighborhood works), your timezone, the days you are free all day and the days you are free only in the evening, and your landing day: the day the weekly scan runs, so new events are waiting that morning. Add interests, things to avoid and a budget if you like. Under "Where you are" you can list other places you go. Each gets a run of its own in every scan.
2. **Sources.** Add pages to watch: a library's event calendar, a venue's listings, a city's events page. Choose where each is checked (near home, or one of your other places). Turn on "Open it in a browser" for pages that only show their events with JavaScript. If a page has one address per day, put `{date}` (or `{date:FORMAT}` with PHP date letters) where the date goes.
3. **Upcoming.** Press "Check now" to run a scan right away. It takes a minute or two for each place. New events appear on Upcoming.
4. Mark what you find. Later runs learn from it.

## Connecting Claude Code

Sunshine runs an MCP server at `/mcp`. Claude Code can use it to read your preferences, list, check, add and remove watched pages, start and read ad hoc searches, read scans, and list what is on Upcoming. It acts as the user whose token it uses.

1. Open the **Claude** page in Sunshine ("Connect Claude").
2. Press **Create token**. The token is shown once.
3. Copy the command it shows and run it in a terminal. It looks like this:

   ```bash
   claude mcp add --transport http --scope user sunshine https://sunshine.example.com/mcp --header "Authorization: Bearer <token>"
   ```

4. Start a new Claude Code session.

Each user has one token. Pressing **Replace token** ends the old one.

## Security notes

- **Sunshine fetches web pages from your server.** The scan reads watched pages, follows their redirects, and can open them in headless Chromium. It only connects to public addresses, checks each redirect, follows robots.txt, and the browser only reaches the web through a proxy that allows public addresses on ports 80 and 443. Even so, run it somewhere where outgoing web requests to arbitrary sites are acceptable.
- **Accounts.** There is no sign-up page, and every account in the database can use the app. Only create accounts for people you trust with your Anthropic bill.
- **Production settings.** Set `APP_ENV=production`, `APP_DEBUG=false` and `APP_URL` to the address you serve it at, and install with `composer install --no-dev`. Outside the `local` environment, Sunshine only answers requests for `APP_URL`'s host (and its subdomains).
- **HTTPS.** Serve the app over HTTPS. MCP tokens are sent as bearer tokens on every request.
- **Secrets.** Keep `.env` out of the web root and out of version control.

## Local development

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan db:seed   # creates test@example.com
(cd browser && npm ci && npx playwright install chromium)
```

Serve the app however you like (`php artisan serve`, Herd, Valet). Then run:

```bash
composer run dev
```

That starts the queue worker, the scheduler and a log tail. It does not start a web server or Vite.

Log in as `test@example.com` with the password `password`.

Run the tests:

```bash
php artisan test --compact
```

Tests never reach the web or the Anthropic API. The agents and the browser are faked.

## License

See LICENSE.
