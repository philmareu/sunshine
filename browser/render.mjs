// Renders one page in headless Chromium for Sunshine (app/Ai/PageBrowser.php), for pages that fill in their events
// with JavaScript, and prints what it showed as JSON: {"status": 200, "url": "...", "html": "..."}, or {"error": "..."}
// with exit code 1.
//
//     node render.mjs <url> <user agent> <seconds>
//
// The page and everything it loads come from the web, so Chromium only reaches the web through a proxy here, which
// connects to public addresses only, on ports 80 and 443, and to the address it checked, so DNS can't swap in a
// private one afterwards. Only the page Sunshine checked is opened: other navigations and frames are refused, so they
// don't get round the robots.txt check Sunshine made for that page. Images, media and fonts aren't loaded, and the
// browser says it is Sunshine's.

import { chromium } from 'playwright';
import { lookup } from 'node:dns/promises';
import { createServer, request as httpRequest } from 'node:http';
import { BlockList, connect, isIP } from 'node:net';

const [url, sunshine, seconds = '30'] = process.argv.slice(2);
const deadline = Date.now() + Number(seconds) * 1000;
const PORTS = new Set([80, 443]);
const SKIPPED = new Set(['image', 'media', 'font']);
const STILL_WAITING = 15_000;
// The most of a page's HTML handed back, as PageCheck::MAX_BYTES caps a page Sunshine gets itself.
const MAX_HTML = 10 * 1024 * 1024;

const privateRanges = new BlockList();

for (const [network, prefix] of [
    ['0.0.0.0', 8], ['10.0.0.0', 8], ['100.64.0.0', 10], ['127.0.0.0', 8], ['169.254.0.0', 16], ['172.16.0.0', 12],
    ['192.0.0.0', 24], ['192.0.2.0', 24], ['192.168.0.0', 16], ['198.18.0.0', 15], ['198.51.100.0', 24],
    ['203.0.113.0', 24], ['224.0.0.0', 4], ['240.0.0.0', 4],
]) {
    privateRanges.addSubnet(network, prefix, 'ipv4');
}

for (const [network, prefix] of [
    ['::', 128], ['::1', 128], ['64:ff9b::', 96], ['100::', 64], ['2001:db8::', 32], ['fc00::', 7], ['fe80::', 10],
    ['ff00::', 8],
]) {
    // IPv4 addresses written as IPv6 (::ffff:10.0.0.1) are caught by the IPv4 ranges: BlockList checks both forms.
    privateRanges.addSubnet(network, prefix, 'ipv6');
}

/** The address to connect to for a host, refusing a host with any address in a private or reserved range. */
async function publicAddress(host) {
    const name = host.replace(/^\[|\]$/g, '');
    const addresses = isIP(name) ? [{ address: name, family: isIP(name) }] : await lookup(name, { all: true, order: 'ipv4first' });

    if (addresses.length === 0 || addresses.some(({ address, family }) => privateRanges.check(address, family === 6 ? 'ipv6' : 'ipv4'))) {
        throw new Error(`${host} isn't a public address.`);
    }

    return addresses[0].address;
}

const proxy = createServer(async (request, response) => {
    // A plain http request, whose request line holds the whole address.
    try {
        const target = new URL(request.url);
        const port = Number(target.port || 80);

        if (target.protocol !== 'http:' || !PORTS.has(port)) {
            throw new Error('refused');
        }

        const headers = { ...request.headers, host: target.host };
        delete headers['proxy-connection'];
        delete headers['proxy-authorization'];

        const upstream = httpRequest({ host: await publicAddress(target.hostname), port, method: request.method, path: target.pathname + target.search, headers }, (answer) => {
            response.writeHead(answer.statusCode ?? 502, answer.headers);
            answer.pipe(response);
        });

        upstream.on('error', () => response.destroy());
        request.pipe(upstream);
    } catch {
        response.writeHead(403).end();
    }
});

proxy.on('connect', async (request, client, head) => {
    // A tunnel for https and websockets, to host:port.
    try {
        const [, host, portText] = request.url.match(/^(\[[^\]]+\]|[^:]+):(\d+)$/) ?? [];
        const port = Number(portText);

        if (!host || !PORTS.has(port)) {
            throw new Error('refused');
        }

        const upstream = connect(port, await publicAddress(host), () => {
            client.write('HTTP/1.1 200 Connection Established\r\n\r\n');
            upstream.write(head);
            upstream.pipe(client);
            client.pipe(upstream);
        });

        upstream.on('error', () => client.destroy());
        client.on('error', () => upstream.destroy());
    } catch {
        client.end('HTTP/1.1 403 Forbidden\r\n\r\n');
    }
});

/** The address without its fragment or a trailing slash, to tell whether a navigation is to the page itself. */
const page = (address) => address.replace(/#.*$/, '').replace(/\/+$/, '');

function finish(result, code) {
    process.stdout.write(JSON.stringify(result), () => process.exit(code));
}

let browser;

try {
    await new Promise((resolve) => proxy.listen(0, '127.0.0.1', resolve));

    browser = await chromium.launch({
        proxy: { server: `http://127.0.0.1:${proxy.address().port}`, bypass: '<-loopback>' },
        args: ['--disable-quic', '--force-webrtc-ip-handling-policy=disable_non_proxied_udp'],
    });

    const probe = await browser.newPage();
    const userAgent = `${await probe.evaluate(() => navigator.userAgent)} ${sunshine}`;
    await probe.close();

    const context = await browser.newContext({ userAgent, serviceWorkers: 'block', acceptDownloads: false, viewport: { width: 1280, height: 2000 } });
    const tab = await context.newPage();

    await context.route('**/*', (route) => {
        const request = route.request();

        if (SKIPPED.has(request.resourceType())) {
            return route.abort();
        }

        if (request.isNavigationRequest() && (request.frame() !== tab.mainFrame() || page(request.url()) !== page(url))) {
            return route.abort();
        }

        return route.continue();
    });

    const response = await tab.goto(url, { waitUntil: 'load', timeout: Math.max(1000, deadline - Date.now() - 5000) });

    // Calendars often fill in their events after the page has loaded, some seconds after the network goes quiet, so
    // wait for that, then until the page's text has stopped growing for two seconds, for STILL_WAITING at most.
    const waitUntil = Math.min(deadline - 3000, Date.now() + STILL_WAITING);

    await tab.waitForLoadState('networkidle', { timeout: Math.max(1000, waitUntil - Date.now()) }).catch(() => {});

    for (let length = -1, steady = 0; steady < 4 && Date.now() < waitUntil;) {
        await tab.waitForTimeout(500);

        const now = await tab.evaluate(() => document.body?.innerText.length ?? 0);
        steady = now === length ? steady + 1 : 0;
        length = now;
    }

    const result = { status: response?.status() ?? null, url: tab.url(), html: (await tab.content()).slice(0, MAX_HTML) };

    await browser.close();
    proxy.closeAllConnections();
    proxy.close();

    finish(result, 0);
} catch (error) {
    await browser?.close().catch(() => {});
    finish({ error: String(error?.message ?? error).split('\n')[0] }, 1);
}
