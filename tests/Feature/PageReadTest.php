<?php

use App\Ai\Agents\PageReader;
use App\Ai\PageRead;
use App\Enums\SearchKind;
use App\Models\AiUsage;
use App\Models\Search;
use App\Models\Source;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->travelTo('2026-11-01 09:00:00');

    $user = User::factory()->create(['home_location' => 'Springfield']);
    $this->search = Search::factory()->for($user)->create(['kind' => SearchKind::Discovery, 'location' => 'Springfield', 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-22']);
    $this->source = Source::factory()->for($user)->create(['name' => 'Venue', 'url' => 'https://venue.example/events']);
});

/** A long enough stretch of text that a page isn't taken for one shown with JavaScript. */
function filler(): string
{
    return str_repeat('All events are open to the public unless noted. ', 5);
}

function fakeVenue(string $body, string $contentType = 'text/html; charset=UTF-8', int $status = 200, ?string $robots = null): void
{
    Http::fake([
        'venue.example/robots.txt' => $robots === null ? Http::response('', 404) : Http::response($robots),
        'venue.example/events' => Http::response($body, $status, ['Content-Type' => $contentType]),
    ]);
}

it('reads a page for the scout, keeping its links and leaving out scripts and menus', function () {
    fakeVenue('<html><head><title>Venue</title><script>var hidden = "2027-06-01";</script></head><body>
        <nav><a href="/about">About us</a></nav>
        <article><h2><a href="/events/fall-concert">Fall concert</a></h2><p>Saturday, Nov 7 · 7 PM</p><a href="/events/fall-concert">More</a></article>
        <p>'.filler().'</p>
    </body></html>');

    PageReader::fake([['events' => [['title' => 'Fall concert', 'date' => '2026-11-07', 'start_time' => '19:00']], 'note' => 'Lists shows through November.']]);

    $read = PageRead::of($this->source, $this->search);

    expect($read)
        ->read->toBeTrue()
        ->events->toHaveCount(1)
        ->note->toBe('Lists shows through November.');

    PageReader::assertPrompted(fn ($prompt) => $prompt->contains("Fall concert <https://venue.example/events/fall-concert>\nSaturday, Nov 7 · 7 PM\nMore\n")
        && ! $prompt->contains('hidden')
        && ! $prompt->contains('About us'));

    expect(AiUsage::sole())->source_id->toBe($this->source->id)->search_id->toBe($this->search->id);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://venue.example/events' && $request->hasHeader('User-Agent', 'Sunshine (reads the event pages a family watches)'));
});

it('reads feeds and calendars that the scout\'s fetch may refuse', function (string $body, string $contentType, string $reads) {
    fakeVenue($body, $contentType);

    PageReader::fake([['events' => [], 'note' => 'Nothing in range.']]);

    expect(PageRead::of($this->source, $this->search)->read)->toBeTrue();

    PageReader::assertPrompted(fn ($prompt) => $prompt->contains($reads));
})->with([
    'RSS, with each item on its own lines' => [
        '<rss><channel><item><title>Brick Builders Club</title><link>https://venue.example/bricks</link><description>&lt;p&gt;Every Sunday at 2 PM&lt;/p&gt; '.filler().'</description></item><item><title>Story time</title></item></channel></rss>',
        'application/rss+xml',
        'Brick Builders Club https://venue.example/bricks Every Sunday at 2 PM',
    ],
    'Atom, with its links' => [
        '<feed><entry><title>Bird walk</title><link href="https://venue.example/birds"/><summary>'.filler().'</summary></entry></feed>',
        'application/atom+xml',
        'Bird walk https://venue.example/birds',
    ],
    'iCal, as it is' => [
        "BEGIN:VCALENDAR\nBEGIN:VEVENT\nDTSTART:20261121T190000Z\nSUMMARY:Football vs. Iowa State\nDESCRIPTION:".filler()."\nEND:VEVENT\nEND:VCALENDAR",
        'text/calendar',
        "DTSTART:20261121T190000Z\nSUMMARY:Football vs. Iowa State",
    ],
]);

it('leaves a page to the scout\'s fetch when Sunshine can\'t read it', function (Closure $site, string $note) {
    $site();

    PageReader::fake();

    expect(PageRead::of($this->source, $this->search))
        ->read->toBeFalse()
        ->note->toBe($note);

    PageReader::assertNeverPrompted();
    expect(AiUsage::count())->toBe(0);
})->with([
    'robots.txt turns Anthropic\'s fetcher away' => [
        fn () => fakeVenue('<p>Nov 7</p>'.filler(), robots: "User-agent: Claude-User\nDisallow: /events"),
        'robots.txt turns away Claude-User from https://venue.example/events.',
    ],
    'it turns the server away' => [fn () => fakeVenue('Forbidden', status: 403), 'It answered Sunshine with 403.'],
    'it is a PDF' => [fn () => fakeVenue('%PDF-1.7', 'application/pdf'), 'Sunshine doesn\'t read application/pdf.'],
    'its events are shown with JavaScript' => [
        fn () => fakeVenue('<html><body><div id="calendar">Loading…</div><script>render()</script></body></html>'),
        'Its text is nearly empty, so its events may be shown with JavaScript. Marking it as needing a browser may help.',
    ],
]);

it('follows robots.txt on every address a page redirects to', function () {
    Http::fake([
        'venue.example/robots.txt' => Http::response('', 404),
        'venue.example/events' => Http::response('', 301, ['Location' => 'https://calendar.example/feed']),
        'calendar.example/robots.txt' => Http::response("User-agent: *\nDisallow: /feed"),
    ]);

    PageReader::fake();

    expect(PageRead::of($this->source, $this->search)->note)->toBe('robots.txt turns away Claude-User from https://calendar.example/feed.');

    Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://calendar.example/feed');
});

it('won\'t read a page on a private address', function () {
    $this->source->update(['url' => 'https://internal.example/events']);

    PageReader::fake();

    expect(PageRead::of($this->source, $this->search))
        ->read->toBeFalse()
        ->note->toBe('It leads to a private address, so it wasn\'t checked.');

    Http::assertNothingSent();
});

it('leaves a page to the scout\'s fetch when the reader fails on it', function () {
    fakeVenue('<p>Nov 7</p>'.filler());

    PageReader::fake(fn () => throw new RuntimeException('Overloaded'));

    expect(PageRead::of($this->source, $this->search))
        ->read->toBeFalse()
        ->note->toBe('Sunshine\'s reader failed on it.');
});

it('leaves a page to the scout\'s fetch when the reader runs out of room on it, but keeps what the read cost', function () {
    Http::fake([
        'venue.example/robots.txt' => Http::response('', 404),
        'venue.example/events' => Http::response('<p>Nov 7</p>'.filler(), 200, ['Content-Type' => 'text/html']),
        'api.anthropic.com/*' => Http::response([
            'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-haiku-5-5',
            'content' => [['type' => 'text', 'text' => '{"events":[{"title":"Fall conc']],
            'stop_reason' => 'max_tokens',
            'usage' => ['input_tokens' => 12_000, 'output_tokens' => PageReader::MAX_TOKENS],
        ]),
    ]);

    expect(PageRead::of($this->source, $this->search))
        ->read->toBeFalse()
        ->note->toBe('Sunshine\'s reader ran out of room on it.')
        ->and(AiUsage::sole())->output_tokens->toBe(PageReader::MAX_TOKENS);
});

/** Sunshine's browser showing a page, as browser/render.mjs prints it. */
function fakeBrowser(?string $html, ?int $status = 200, string $url = 'https://venue.example/events'): void
{
    Process::fake(['*render.mjs*' => $html === null
        ? Process::result(json_encode(['error' => 'page.goto: Timeout 40000ms exceeded.']), exitCode: 1)
        : Process::result(json_encode(['status' => $status, 'url' => $url, 'html' => $html])),
    ]);
}

it('shows a page that fills in its events with JavaScript in Sunshine\'s browser before reading it', function () {
    $this->source->update(['needs_browser' => true]);
    fakeVenue('<html><body><div id="calendar">Loading…</div><script>render()</script></body></html>');
    fakeBrowser('<html><body><div id="calendar"><h2><a href="/events/fall-concert">Fall concert</a></h2><p>Saturday, Nov 7 · 7 PM</p></div><p>'.filler().'</p></body></html>');

    PageReader::fake([['events' => [['title' => 'Fall concert', 'date' => '2026-11-07', 'start_time' => '19:00']], 'note' => 'One concert.']]);

    expect(PageRead::of($this->source, $this->search))
        ->read->toBeTrue()
        ->events->toHaveCount(1);

    PageReader::assertPrompted(fn ($prompt) => $prompt->contains('Fall concert <https://venue.example/events/fall-concert>') && ! $prompt->contains('Loading'));
    Process::assertRan(fn (PendingProcess $process): bool => $process->command[1] === 'render.mjs'
        && $process->command[2] === 'https://venue.example/events'
        && $process->command[3] === 'Sunshine (reads the event pages a family watches)');
});

it('leaves a page to the scout\'s fetch when Sunshine\'s browser can\'t show it', function (?string $html, ?int $status, string $url, string $note) {
    $this->source->update(['needs_browser' => true]);
    fakeVenue('<div id="calendar">Loading…</div>', robots: "User-agent: Claude-User\nDisallow: /members");
    fakeBrowser($html, $status, $url);

    PageReader::fake();

    expect(PageRead::of($this->source, $this->search))
        ->read->toBeFalse()
        ->note->toBe($note);

    PageReader::assertNeverPrompted();
})->with([
    'the browser fails' => [null, null, 'https://venue.example/events', 'Sunshine\'s browser couldn\'t show it.'],
    'the site turns the browser away' => ['<p>Forbidden</p>', 403, 'https://venue.example/events', 'It answered Sunshine\'s browser with 403.'],
    'it sends the browser somewhere robots.txt turns it away from' => ['<p>Members only. '.filler().'</p>', 200, 'https://venue.example/members', 'robots.txt turns away Claude-User from https://venue.example/members.'],
    'there is nothing on it even in the browser' => ['<div id="calendar">No events.</div>', 200, 'https://venue.example/events', 'Its text is nearly empty even in Sunshine\'s browser.'],
]);

it('doesn\'t open a page in the browser that robots.txt turns away', function () {
    $this->source->update(['needs_browser' => true]);
    fakeVenue('<p>Nov 7</p>', robots: "User-agent: Claude-User\nDisallow: /events");
    Process::fake();

    expect(PageRead::of($this->source, $this->search)->note)->toBe('robots.txt turns away Claude-User from https://venue.example/events.');

    Process::assertNothingRan();
});

it('tells the reader the dates to list and, on a scan, when the family can go out', function () {
    $this->search->user->update(['free_all_day' => [6], 'free_evenings' => [3]]);

    $instructions = (string) (new PageReader($this->search, 'Maple Falls, OR'))->instructions();

    expect($instructions)
        ->toContain('in or near Maple Falls, OR. Today is 2026-11-01. List the events on it that happen between 2026-11-01 and 2026-11-22.')
        ->toContain('Only list events at times they can go out: any time on Saturday; or in the evening, from about 5 PM, on Wednesday.')
        ->toContain('list it once, on the first date in the range when they can go out,');

    $this->search->update(['kind' => SearchKind::AdHoc]);

    expect((string) (new PageReader($this->search))->instructions())
        ->not->toContain('Only list events at times')
        ->toContain('list it once, on its first date in the range,');
});
