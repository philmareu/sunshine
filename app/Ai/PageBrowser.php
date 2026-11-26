<?php

namespace App\Ai;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Shows a page in headless Chromium (browser/render.mjs), for a page that fills in its events with JavaScript, and
 * gives back what the page showed. The script reaches the web only through its own proxy, which connects to public
 * addresses only, on ports 80 and 443, pinned to the address it checked; and it opens only the page it is given,
 * refusing other navigations and frames, so the robots.txt check made for that page holds. Chromium is heavy, so one
 * page is shown at a time across every run and check.
 */
final class PageBrowser
{
    /**
     * How long Chromium gets to show a page, in seconds, and how long one waits for another to finish first.
     */
    public const TIMEOUT = 45;

    public const WAIT = 60;

    /**
     * @return array{status: ?int, url: string, html: string}
     *
     * @throws RuntimeException with the problem to report, when the page couldn't be shown
     */
    public static function show(string $url, string $userAgent): array
    {
        try {
            return Cache::lock('page-browser', self::TIMEOUT + 15)->block(self::WAIT, fn (): array => self::run($url, $userAgent));
        } catch (LockTimeoutException) {
            throw new RuntimeException('Sunshine\'s browser was busy with other pages.');
        }
    }

    /**
     * @return array{status: ?int, url: string, html: string}
     */
    protected static function run(string $url, string $userAgent): array
    {
        try {
            $result = Process::path(base_path('browser'))
                ->env(array_filter([
                    // Playwright finds its browsers under $HOME/.cache, and Supervisor-run workers often have no HOME.
                    'HOME' => getenv('HOME') ?: (function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['dir'] ?? null) : null),
                    'PLAYWRIGHT_BROWSERS_PATH' => getenv('PLAYWRIGHT_BROWSERS_PATH') ?: null,
                ]))
                ->timeout(self::TIMEOUT + 10)
                ->run([config('sunshine.browser.node'), 'render.mjs', $url, $userAgent, (string) self::TIMEOUT]);
        } catch (ProcessTimedOutException $exception) {
            report($exception);

            throw new RuntimeException('Sunshine\'s browser took too long to show it.');
        }

        $shown = json_decode($result->output(), true);

        if (! is_array($shown) || ! is_string($shown['html'] ?? null) || ! is_string($shown['url'] ?? null)) {
            report(new RuntimeException('Sunshine\'s browser failed on '.$url.': '.(is_array($shown) ? ($shown['error'] ?? 'no page') : $result->errorOutput())));

            throw new RuntimeException('Sunshine\'s browser couldn\'t show it.');
        }

        return ['status' => is_int($shown['status'] ?? null) ? $shown['status'] : null, 'url' => $shown['url'], 'html' => $shown['html']];
    }
}
