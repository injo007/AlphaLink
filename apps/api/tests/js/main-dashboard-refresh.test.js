import assert from 'node:assert/strict';
import test from 'node:test';
import { readFile } from 'node:fs/promises';
import { runInNewContext } from 'node:vm';

const script = await readFile(new URL('../../public/dms-reference/main-dashboard-refresh.js', import.meta.url), 'utf8');

function createBrowser(fetchImplementation) {
    let scheduledRefresh;
    let scheduledInterval;
    let restoredScroll = null;
    const activeLink = {
        matches: (selector) => selector === 'a[href]',
        getAttribute: (attribute) => attribute === 'href' ? '/app/workspaces/workspace-1/drops/monitoring' : null,
    };
    const refreshedLink = {
        getAttribute: (attribute) => attribute === 'href' ? '/app/workspaces/workspace-1/drops/monitoring' : null,
        focus(options) {
            this.focusOptions = options;
        },
    };
    let activeRegion = {
        dataset: {
            refreshUrl: '/app/workspaces/workspace-1/analytics?fragment=1',
            refreshInterval: '300000',
        },
        contains: (element) => element === activeLink,
        querySelectorAll: () => [activeLink],
        replaceWith(nextRegion) {
            activeRegion = nextRegion;
        },
    };
    const originalRegion = activeRegion;
    const refreshedRegion = {
        dataset: { refreshUrl: '/app/workspaces/workspace-1/analytics?fragment=1' },
        querySelectorAll: () => [refreshedLink],
    };
    let assignedLocation = null;

    class MockDOMParser {
        parseFromString(markup) {
            assert.equal(markup, '<section>fresh dashboard</section>');

            return { querySelector: () => refreshedRegion };
        }
    }

    runInNewContext(script, {
        document: {
            querySelector: () => originalRegion,
            activeElement: activeLink,
            importNode: (node) => node,
        },
        window: {
            scrollX: 8,
            scrollY: 246,
            setInterval(callback, interval) {
                scheduledRefresh = callback;
                scheduledInterval = interval;
            },
            scrollTo(x, y) {
                restoredScroll = [x, y];
            },
            location: {
                href: 'http://localhost/app/workspaces/workspace-1/analytics',
                assign(destination) {
                    assignedLocation = destination;
                },
                reload() {
                    assignedLocation = 'reload';
                },
            },
        },
        fetch: fetchImplementation,
        DOMParser: MockDOMParser,
        URL,
    });

    return {
        get activeRegion() {
            return activeRegion;
        },
        get assignedLocation() {
            return assignedLocation;
        },
        get restoredScroll() {
            return restoredScroll;
        },
        get restoredFocusOptions() {
            return refreshedLink.focusOptions;
        },
        get scheduledInterval() {
            return scheduledInterval;
        },
        originalRegion,
        refreshedRegion,
        refresh: () => scheduledRefresh(),
    };
}

test('refreshes dashboard content in place every five minutes', async () => {
    let request;
    const browser = createBrowser(async (url, options) => {
        request = { url, options };

        return {
            ok: true,
            redirected: false,
            status: 200,
            text: async () => '<section>fresh dashboard</section>',
        };
    });

    assert.equal(browser.scheduledInterval, 300000);
    await browser.refresh();

    assert.equal(request.url, '/app/workspaces/workspace-1/analytics?fragment=1');
    assert.equal(request.options.headers.Accept, 'text/html');
    assert.equal(request.options.headers['X-Requested-With'], 'XMLHttpRequest');
    assert.equal(request.options.credentials, 'same-origin');
    assert.equal(browser.activeRegion, browser.refreshedRegion);
    assert.equal(browser.assignedLocation, null);
    assert.deepEqual(browser.restoredScroll, [8, 246]);
    assert.equal(browser.restoredFocusOptions.preventScroll, true);
});

test('keeps the current dashboard snapshot when a refresh request fails', async () => {
    const browser = createBrowser(async () => {
        throw new Error('Network unavailable');
    });

    await browser.refresh();

    assert.equal(browser.activeRegion, browser.originalRegion);
    assert.equal(browser.assignedLocation, null);
});
