<?php

use App\Ai\PageCheck;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    PageCheck::resolveHostsUsing(fn (string $host): array => str_starts_with($host, 'internal.') ? ['10.0.0.5'] : ['93.184.216.34']);
});

afterEach(function () {
    PageCheck::resolveHostsUsing(null);
});

function checkPage(string $url = 'https://venue.example/events'): array
{
    return PageCheck::run($url, CarbonImmutable::parse('2026-11-01'), 21);
}

function fakeSite(string $body, string $contentType = 'text/html; charset=UTF-8', int $status = 200, ?string $robots = null): void
{
    Http::fake([
        'venue.example/robots.txt' => $robots === null ? Http::response('', 404) : Http::response($robots),
        'venue.example/events' => Http::response($body, $status, ['Content-Type' => $contentType]),
    ]);
}

it('passes a page that lists dated events for the coming weeks', function () {
    fakeSite('<html><head><title>Events</title><script>var d = "2027-06-01";</script></head><body>
        <h2>Fall concert</h2><p>Nov 5, 2026 · 7 PM</p>
        <h2>Craft night</h2><p>Tuesday, November 10th</p>
        <h2>Holiday market</h2><p>12/12/2026</p>
    </body></html>');

    expect(checkPage())
        ->status->toBe(200)
        ->content_type->toBe('text/html')
        ->upcoming_dates->toBe(2)
        ->latest_date->toBe('2026-12-12')
        ->problems->toBe([])
        ->summary->toStartWith('Looks readable: dates on 2 of the next 21 days, reaching 2026-12-12');
});

it('flags a page with no dates ahead, like a calendar filled in by JavaScript', function () {
    fakeSite('<html><body><div id="calendar">Loading events…</div><p>Last updated Oct 2, 2026</p></body></html>');

    expect(checkPage())
        ->upcoming_dates->toBe(0)
        ->latest_date->toBeNull()
        ->summary->toContain('No dates from 2026-11-01 on in its text. Its events may be filled in by JavaScript');
});

it('flags a page whose dates only reach the next few days', function () {
    fakeSite('<p>Today: 11/01/2026</p><p>Tomorrow: 11/02/2026</p>');

    expect(checkPage()['summary'])->toBe('Its dates only reach 2026-11-02, so it may list just the next few days.');
});

it('reads the dates in a feed, which Sunshine reads itself', function () {
    fakeSite(<<<'XML'
        <rss><channel><item><title>Brick Builders Club</title><description>&lt;p&gt;Weekly from 2026-11-04 to 2026-11-25&lt;/p&gt;</description><pubDate>Sat, 31 Oct 2026 12:00:00 -0500</pubDate></item></channel></rss>
        XML, 'application/rss+xml');

    expect(checkPage())
        ->upcoming_dates->toBe(1)
        ->latest_date->toBe('2026-11-25')
        ->problems->toBe([]);
});

it('flags a content type neither Sunshine nor the fetch tool reads', function () {
    fakeSite('BINARY', 'application/octet-stream');

    expect(checkPage()['problems'])->toContain('Its content type is application/octet-stream, which neither Sunshine nor Anthropic\'s web fetch reads.');
});

it('reads the dates in an iCal calendar', function () {
    fakeSite("BEGIN:VEVENT\nDTSTART:20261121T190000Z\nSUMMARY:Football vs. Iowa State\nEND:VEVENT", 'text/calendar');

    expect(checkPage())
        ->latest_date->toBe('2026-11-21')
        ->problems->toBe([]);
});

it('counts a date of today as coming up for someone west of UTC', function () {
    fakeSite('<h1>Everything on Sat, Oct 31</h1><p>Today | 8 pm</p>');

    $today = CarbonImmutable::today('America/Chicago')->setDate(2026, 10, 31);

    expect(PageCheck::run('https://venue.example/events', $today, 21, oneDay: true))
        ->upcoming_dates->toBe(1)
        ->latest_date->toBe('2026-10-31')
        ->problems->toBe([]);
});

it('takes a date without a year as the nearest one', function () {
    fakeSite('<p>Exhibit opens Jan 9</p><p>Season ended Oct 3</p>');

    expect(checkPage())
        ->upcoming_dates->toBe(0)
        ->latest_date->toBe('2027-01-09');
});

it('flags a page too long for a scan to read to the end', function () {
    fakeSite('<p>Nov 3, 2026</p><p>Nov 20, 2026</p>'.str_repeat('<p>A long description of something.</p>', 7_000));

    expect(checkPage()['summary'])->toMatch('/^At about [\d,]+ tokens it is cut off after about 50,000, so a scan only reads the start of it\.$/');
});

it('says when the page turns this check away', function () {
    fakeSite('Forbidden', 'text/html', 403);

    expect(checkPage())
        ->status->toBe(403)
        ->summary->toBe("It answered this check with 403, so a scan leaves it to the scout's fetch. Some sites turn away servers but let Anthropic's fetcher in, so a scan may still read it.");
});

it('flags a robots.txt that turns away Anthropic\'s fetcher from the page', function () {
    fakeSite('<p>Nov 3, 2026</p><p>Nov 20, 2026</p>', robots: "User-agent: *\nDisallow: /admin\n\nUser-agent: Claude-User\nUser-agent: ClaudeBot\nDisallow: /events");

    expect(checkPage())
        ->robots_turns_away->toBe(['Claude-User', 'ClaudeBot'])
        ->summary->toBe("robots.txt turns away Claude-User, the name Anthropic's fetcher uses, whose rules Sunshine follows too, so a scan can't read it.");
});

it('only cautions when robots.txt turns away Anthropic\'s other crawlers', function () {
    fakeSite('<p>Nov 3, 2026</p><p>Nov 20, 2026</p>', robots: "User-agent: ClaudeBot\nDisallow: /\n\nUser-agent: *\nDisallow: /\nAllow: /events");

    expect(checkPage())
        ->robots_turns_away->toBe(['ClaudeBot'])
        ->summary->toBe('robots.txt turns away ClaudeBot, though not Claude-User, so a scan should still get in.');
});

it('won\'t reach a private address, directly or by name', function (string $url) {
    expect(PageCheck::run($url, CarbonImmutable::parse('2026-11-01'), 21))
        ->status->toBeNull()
        ->summary->toBe('It leads to a private address, so it wasn\'t checked.');
})->with([
    'the server itself' => 'http://127.0.0.1/admin',
    'cloud metadata' => 'http://169.254.169.254/latest/meta-data/',
    'a name for the private network' => 'https://internal.example/status',
    'a tailnet or shared address' => 'http://100.64.0.1/',
    'a cloud metadata service in the shared range' => 'http://100.100.100.200/latest/meta-data/',
    'the server itself, written as IPv6' => 'http://[::ffff:127.0.0.1]/',
    'the server itself, through NAT64' => 'http://[64:ff9b::7f00:1]/',
    'the server itself, through 6to4' => 'http://[2002:7f00:1::1]/',
]);

it('won\'t reach a port other than a web page\'s', function () {
    Http::fake();

    expect(PageCheck::run('http://venue.example:8000/', CarbonImmutable::parse('2026-11-01'), 21))
        ->status->toBeNull()
        ->summary->toBe('It leads to a port other than a web page\'s, so it wasn\'t checked.');

    Http::assertNothingSent();
});

it('follows a redirect to a public page, but not into a private network', function () {
    Http::fake([
        'venue.example/robots.txt' => Http::response('', 404),
        'venue.example/events' => Http::response('', 301, ['Location' => '/calendar/']),
        'venue.example/calendar/' => Http::response('<p>Nov 5, 2026</p><p>Nov 20, 2026</p>', 200, ['Content-Type' => 'text/html']),
        'venue.example/old' => Http::response('', 302, ['Location' => 'https://internal.example/']),
    ]);

    expect(checkPage()['summary'])->toStartWith('Looks readable')
        ->and(checkPage('https://venue.example/old')['summary'])->toBe('It leads to a private address, so it wasn\'t checked.');
});
