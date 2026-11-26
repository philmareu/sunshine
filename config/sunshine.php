<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Discovery
    |--------------------------------------------------------------------------
    |
    | The scheduled scan of each user's home area that fills Upcoming. It runs
    | weekly on the user's landing day (routes/console.php) and looks this many
    | days ahead, so the next week is still covered the day before the next one.
    |
    */

    'discovery' => [
        'days_ahead' => (int) env('DISCOVERY_DAYS_AHEAD', 21),
    ],

    /*
    |--------------------------------------------------------------------------
    | Pages
    |--------------------------------------------------------------------------
    |
    | Before each run, Sunshine reads the pages it opens itself and a cheap
    | model lists their events for the scout, which then doesn't fetch them.
    | Pages Sunshine can't read are left to the scout's fetch. Turning this
    | off leaves every page to the scout, as before.
    |
    */

    'pages' => [
        'read' => (bool) env('READ_PAGES', true),
        'reader_model' => env('PAGE_READER_MODEL', 'claude-haiku-5-5'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Taste
    |--------------------------------------------------------------------------
    |
    | About once a week, at the start of a scan, a cheap model sums up what
    | the family says yes to and skips from what they made of past
    | suggestions (App\Ai\TasteProfile), and the scout reads that summary.
    |
    */

    'taste' => [
        'model' => env('TASTE_MODEL', 'claude-haiku-5-5'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Browser
    |--------------------------------------------------------------------------
    |
    | A page that fills in its events with JavaScript is marked as needing a
    | browser, and Sunshine shows it in headless Chromium (browser/render.mjs,
    | with Playwright) before reading it. This is the Node binary that runs it.
    |
    */

    'browser' => [
        'node' => env('BROWSER_NODE', 'node'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Photos
    |--------------------------------------------------------------------------
    |
    | Where photos from activities are kept. They are family photos, so the
    | disk should be private: they are shown through short-lived links. The
    | local disk serves those itself; S3 needs league/flysystem-aws-s3-v3.
    |
    */

    'photos' => [
        'disk' => env('PHOTOS_DISK', 'local'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Pricing
    |--------------------------------------------------------------------------
    |
    | USD per million tokens, used to put a cost on each AI run. Cache writes
    | use the 5-minute rate. Models missing here are tracked without a cost.
    | Source: https://platform.claude.com/docs/en/about-claude/pricing (checked 2026-10-27).
    |
    */

    'pricing' => [
        'models' => [
            'claude-opus-5-5' => ['input' => 4.00, 'cache_write' => 5.00, 'cache_read' => 0.20, 'output' => 20.00],
            'claude-sonnet-5-5' => ['input' => 2.00, 'cache_write' => 2.50, 'cache_read' => 0.10, 'output' => 10.00],
            'claude-haiku-5-5' => [
                'input' => 0.10, 'cache_write' => 0.125, 'cache_read' => 0.01, 'output' => 0.50,
                // Requests whose prompt is over 100,000 tokens pay these instead.
                'long_context' => ['over' => 100_000, 'input' => 0.50, 'cache_write' => 0.625, 'cache_read' => 0.05, 'output' => 2.50],
            ],
        ],

        // $10 per 1,000 searches. Web fetch has no charge beyond tokens.
        'web_search' => 0.01,
    ],

];
