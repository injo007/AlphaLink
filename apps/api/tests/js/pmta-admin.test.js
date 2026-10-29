import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const view = readFileSync(new URL('../../resources/views/console/campaigns/pmta-admin.blade.php', import.meta.url), 'utf8');

test('PMTA Admin explains the public-IP HTTP fallback when an HTTPS frame is unavailable', () => {
    assert.match(view, /servers without a DNS-managed certificate use the public IP over HTTP/i);
    assert.match(view, /PMTA uses the public IP over HTTP · open the link in a new tab/);
    assert.match(view, /link\.textContent = `\$\{panelUrl\.protocol\}\/\/\$\{panelUrl\.host\}`/);
});

const restartHelperStart = view.indexOf('            let restartRunNumber = 0;');
const restartHelperEnd = view.indexOf('\n\n            servers.addEventListener', restartHelperStart);
assert.notEqual(restartHelperStart, -1, 'PMTA restart helper exists');
assert.notEqual(restartHelperEnd, -1, 'PMTA restart helper closes');
const restartHelper = view.slice(restartHelperStart, restartHelperEnd);

const restartHandlerStart = view.indexOf("            restartButton.addEventListener('click', async () => {");
const restartHandlerEndMarker = "\n            });\n            document.getElementById('pmta-admin-stop-restart')";
const restartHandlerEnd = view.indexOf(restartHandlerEndMarker, restartHandlerStart);
assert.notEqual(restartHandlerStart, -1, 'PMTA Restart click handler exists');
assert.notEqual(restartHandlerEnd, -1, 'PMTA Restart click handler closes');
const restartHandler = view.slice(restartHandlerStart, restartHandlerEnd + '\n            });'.length);

const restartStopHandlerStart = view.indexOf("            document.getElementById('pmta-admin-stop-restart').addEventListener('click', () => {");
const restartStopHandlerEndMarker = "\n            });\n\n            document.getElementById('pmta-admin-clear')";
const restartStopHandlerEnd = view.indexOf(restartStopHandlerEndMarker, restartStopHandlerStart);
assert.notEqual(restartStopHandlerStart, -1, 'PMTA Restart Stop handler exists');
assert.notEqual(restartStopHandlerEnd, -1, 'PMTA Restart Stop handler closes');
const restartStopHandler = view.slice(restartStopHandlerStart, restartStopHandlerEnd + '\n            });'.length);

const scheduleHelperStart = view.indexOf('            const scheduleSelectedServers = async (');
const scheduleHelperEnd = view.indexOf('\n\n            let restartRunNumber', scheduleHelperStart);
assert.notEqual(scheduleHelperStart, -1, 'PMTA Schedule helper exists');
assert.notEqual(scheduleHelperEnd, -1, 'PMTA Schedule helper closes');
const scheduleHelper = view.slice(scheduleHelperStart, scheduleHelperEnd);

const scheduleHandlerStart = view.indexOf("            document.querySelectorAll('.pmta-admin-action').forEach((button) => button.addEventListener('click', async () => {");
const scheduleHandlerEndMarker = '\n\n            document.getElementById(\'pmta-admin-stop-schedule\')';
const scheduleHandlerEnd = view.indexOf(scheduleHandlerEndMarker, scheduleHandlerStart);
assert.notEqual(scheduleHandlerStart, -1, 'PMTA Schedule click handler exists');
assert.notEqual(scheduleHandlerEnd, -1, 'PMTA Schedule click handler closes');
const scheduleHandler = view.slice(scheduleHandlerStart, scheduleHandlerEnd);

const scheduleStopHandlerStart = view.indexOf("            document.getElementById('pmta-admin-stop-schedule').addEventListener('click', () => {");
const scheduleStopHandlerEndMarker = '\n\n            restartButton.addEventListener';
const scheduleStopHandlerEnd = view.indexOf(scheduleStopHandlerEndMarker, scheduleStopHandlerStart);
assert.notEqual(scheduleStopHandlerStart, -1, 'PMTA Schedule STOP handler exists');
assert.notEqual(scheduleStopHandlerEnd, -1, 'PMTA Schedule STOP handler closes');
const scheduleStopHandler = view.slice(scheduleStopHandlerStart, scheduleStopHandlerEnd);

function createDeferred() {
    let resolve;
    const promise = new Promise((done) => { resolve = done; });

    return { promise, resolve };
}

function clone(value) {
    return JSON.parse(JSON.stringify(value));
}

function createRestartHarness({ selected = [], seconds = 0 } = {}) {
    const requests = [];
    const renderedResults = [];
    const intervals = [];
    const clearedIntervals = [];
    const alerts = [];
    const buttonListeners = new Map();
    const stopButtonListeners = new Map();
    const buttonAttributes = new Map();
    const button = {
        disabled: false,
        innerHTML: 'RESTART',
        setAttribute(name, value) { buttonAttributes.set(name, value); },
        removeAttribute(name) { buttonAttributes.delete(name); },
        addEventListener(event, listener) { buttonListeners.set(event, listener); },
    };
    const stopButton = { addEventListener(event, listener) { stopButtonListeners.set(event, listener); } };
    const status = { added: [] };
    const env = {
        Array,
        Map,
        Promise,
        root: { dataset: { restartUrl: '/pmta/restart' } },
        restartButton: button,
        restartButtonLabel: 'RESTART',
        selectedServers: () => selected,
        servers: { focused: false, focus() { this.focused = true; } },
        results: { replaceChildren() { renderedResults.push([]); } },
        resultsWrap: { classList: { add(value) { status.added.push(value); }, remove() {} } },
        renderResults(items) { renderedResults.push(clone(items)); },
        postAction(url, fields, runNumber, options) {
            const deferred = createDeferred();
            requests.push({ url, fields: clone(fields), runNumber, options: clone(options), deferred });

            return deferred.promise;
        },
        document: {
            getElementById(id) {
                if (id === 'pmta-admin-stop-restart') return stopButton;
                assert.equal(id, 'pmta-admin-restart-seconds');

                return { value: String(seconds) };
            },
        },
        window: {
            PmtaControls: {
                async alert(title, message) { alerts.push({ title, message }); },
            },
            AlphaLinkToast: { show() {} },
            setInterval(callback, milliseconds) {
                intervals.push({ callback, milliseconds });

                return intervals.length;
            },
            clearInterval(id) { clearedIntervals.push(id); },
        },
    };
    const setup = `
        let restartTimer = null;
        ${restartHelper}
        ${restartHandler}
        ${restartStopHandler}
        ({ restartSelectedServers, get restartTimer() { return restartTimer; } })
    `;
    const runtime = vm.runInNewContext(setup, env);

    return {
        alerts,
        button,
        buttonAttributes,
        buttonListeners,
        clearedIntervals,
        env,
        intervals,
        requests,
        renderedResults,
        runtime,
        status,
        stopButtonListeners,
    };
}

function createScheduleHarness({ selected = [], seconds = 0, tags = ['*/*'] } = {}) {
    const requests = [];
    const renderedResults = [];
    const intervals = [];
    const clearedIntervals = [];
    const buttonListeners = new Map();
    const stopButtonListeners = new Map();
    const button = {
        dataset: { action: 'schedule' },
        disabled: false,
        addEventListener(event, listener) { buttonListeners.set(event, listener); },
    };
    const stopButton = { addEventListener(event, listener) { stopButtonListeners.set(event, listener); } };
    const status = { hidden: true, textContent: '' };
    const env = {
        Array,
        Map,
        Promise,
        button,
        buttonListeners,
        stopButtonListeners,
        selected,
        tags,
        seconds,
        status,
        scheduleStatus: status,
        scheduleButton: button,
        selectedServers: () => selected,
        selectedTags: () => tags,
        commandForAction(_action, _queue, values) { return values.map((tag) => `schedule ${tag}`).join('\n'); },
        renderResults(items) { renderedResults.push(clone(items)); },
        queueAction(action, serverIds, values, queue, runNumber, options) {
            const deferred = createDeferred();
            requests.push({ action, serverIds: clone(serverIds), values: clone(values), queue, runNumber, options: clone(options), deferred });

            return deferred.promise;
        },
        document: {
            querySelectorAll(selector) {
                assert.equal(selector, '.pmta-admin-action');

                return [button];
            },
            querySelector(selector) {
                assert.equal(selector, 'input[name="pmta-queue"]:checked');

                return { value: 'domain' };
            },
            getElementById(id) {
                if (id === 'pmta-admin-seconds') return { value: String(seconds), focus() {} };
                assert.equal(id, 'pmta-admin-stop-schedule');

                return stopButton;
            },
        },
        window: {
            setInterval(callback, milliseconds) {
                intervals.push({ callback, milliseconds });

                return intervals.length;
            },
            clearInterval(id) { clearedIntervals.push(id); },
        },
    };
    const setup = `
        let scheduleTimer = null;
        let scheduleRuns = 0;
        let scheduleResponseCount = 0;
        let scheduleServerCount = 0;
        let scheduleInFlight = 0;
        let latestScheduleStatusRun = 0;
        let latestDisplayedRun = 0;
        let scheduleActive = false;
        let scheduleStopReason = null;
        const scheduleResultsByServer = new Map();
        const scheduleStatus = status;
        const scheduleButton = button;
        ${view.slice(view.indexOf('            const refreshStoppedScheduleStatus ='), view.indexOf('\n            const monitorRefreshMs', view.indexOf('            const refreshStoppedScheduleStatus =')))}
        const selectedServers = () => Array.from(selected);
        const selectedTags = () => Array.from(tags);
        ${scheduleHelper}
        ${scheduleHandler}
        ${scheduleStopHandler}
        ({
            buttonListeners,
            scheduleSelectedServers,
            scheduleResultsByServer,
            stopButtonListeners,
            get scheduleActive() { return scheduleActive; },
            get scheduleInFlight() { return scheduleInFlight; },
            get scheduleResponseCount() { return scheduleResponseCount; },
            get scheduleTimer() { return scheduleTimer; },
        })
    `;
    const runtime = vm.runInNewContext(setup, env);

    return {button, buttonListeners, clearedIntervals, intervals, renderedResults, requests, runtime, status, stopButtonListeners};
}

test('Clear removes visible PMTA selection and results without stopping active loops', () => {
    const handlerStart = view.indexOf("document.getElementById('pmta-admin-clear').addEventListener('click', () => {");
    assert.notEqual(handlerStart, -1, 'PMTA Clear click handler exists');

    const handlerEndMarker = '\n            });';
    const handlerEnd = view.indexOf(handlerEndMarker, handlerStart);
    assert.notEqual(handlerEnd, -1, 'PMTA Clear click handler closes');

    let tagCount = 1;
    let panelDrawCount = 0;
    let selectionRememberCount = 0;
    let tagsRenderCount = 0;
    let resultRowsCleared = false;
    let resultHidden = false;
    const selectedOption = { selected: true };
    const scheduleButton = { disabled: true };
    const restartButton = { disabled: true };
    const context = {
        Array,
        clearHandler: null,
        document: {
            getElementById: () => ({ addEventListener: (_event, handler) => { context.clearHandler = handler; } }),
        },
        window: {
            jQuery: null,
            clearIntervalCalls: [],
            clearInterval(timer) { this.clearIntervalCalls.push(timer); },
        },
        servers: { options: [selectedOption] },
        tags: { clear() { tagCount = 0; } },
        localStorage: { value: null, setItem(_key, value) { this.value = value; } },
        tagEntry: { value: 'queued-tag' },
        renderTags() { tagsRenderCount += 1; },
        resultsWrap: { classList: { add(className) { resultHidden = className === 'd-none'; } } },
        results: { replaceChildren() { resultRowsCleared = true; } },
        rememberSelection() { selectionRememberCount += 1; },
        drawPanels() { panelDrawCount += 1; },
        scheduleButton,
        restartButton,
    };
    const handler = view.slice(handlerStart, handlerEnd + handlerEndMarker.length);
    const runClear = vm.runInNewContext(`
        let scheduleTimer = 17;
        let restartTimer = 23;
        ${handler}
        clearHandler();
        ({ scheduleTimer, restartTimer, scheduleDisabled: scheduleButton.disabled, restartDisabled: restartButton.disabled });
    `, context);

    assert.equal(runClear.scheduleTimer, 17);
    assert.equal(runClear.restartTimer, 23);
    assert.equal(runClear.scheduleDisabled, true);
    assert.equal(runClear.restartDisabled, true);
    assert.deepEqual(context.window.clearIntervalCalls, []);
    assert.equal(selectedOption.selected, false);
    assert.equal(tagCount, 0);
    assert.equal(context.localStorage.value, '');
    assert.equal(context.tagEntry.value, '');
    assert.equal(tagsRenderCount, 1);
    assert.equal(resultHidden, true);
    assert.equal(resultRowsCleared, true);
    assert.equal(selectionRememberCount, 1);
    assert.equal(panelDrawCount, 1);
});

test('Restart dispatches one independent concurrent request per selected server', async () => {
    const page = createRestartHarness();
    const serverNames = new Map([['server-a', 'Server A | 192.0.2.10'], ['server-b', 'Server B | 192.0.2.11']]);

    const pending = page.runtime.restartSelectedServers(['server-a', 'server-b'], serverNames, true);

    assert.equal(page.requests.length, 2);
    assert.deepEqual(page.requests.map(({ url, fields, options }) => clone({ url, fields, options })), [
        { url: '/pmta/restart', fields: { server_ids: ['server-a'] }, options: { render: false, commandText: 'RESTART' } },
        { url: '/pmta/restart', fields: { server_ids: ['server-b'] }, options: { render: false, commandText: 'RESTART' } },
    ]);
    assert.deepEqual(page.renderedResults.at(-1).map(({ name, result }) => ({ name, result })), [
        { name: 'Server A | 192.0.2.10', result: 'Restarting…' },
        { name: 'Server B | 192.0.2.11', result: 'Restarting…' },
    ]);

    page.requests[1].deferred.resolve({ received: true, items: [{ id: 'server-b', name: 'Server B', result: 'Restarted', success: true }] });
    page.requests[0].deferred.resolve({ received: true, items: [{ id: 'server-a', name: 'Server A', result: 'Restarted', success: true }] });
    await pending;

    assert.deepEqual(page.renderedResults.at(-1).map(({ name, result }) => ({ name, result })), [
        { name: 'Server A', result: 'Restarted' },
        { name: 'Server B', result: 'Restarted' },
    ]);
    assert.equal(page.button.disabled, false);
    assert.equal(page.button.innerHTML, 'RESTART');
    assert.equal(page.buttonAttributes.has('aria-busy'), false);
});

test('Restart starts its periodic requests immediately and repeats at the selected seconds interval', async () => {
    const selected = [
        { value: 'server-a', textContent: 'Server A | 192.0.2.10' },
        { value: 'server-b', textContent: 'Server B | 192.0.2.11' },
    ];
    const page = createRestartHarness({ selected, seconds: 5 });

    await page.buttonListeners.get('click')();

    assert.equal(page.requests.length, 2, 'both selected servers are requested before the first response');
    assert.deepEqual(page.intervals.map(({ milliseconds }) => milliseconds), [5000]);
    assert.equal(page.runtime.restartTimer, 1);
    assert.equal(page.button.disabled, true);
    assert.equal(page.button.innerHTML, '<i class="fas fa-spinner fa-spin" aria-hidden="true"></i> RESTARTING');
    assert.equal(page.buttonAttributes.get('aria-busy'), 'true');

    page.intervals[0].callback();

    assert.equal(page.requests.length, 4, 'the next interval also fans out one request per server');
    assert.deepEqual(page.requests.slice(2).map(({ fields }) => clone(fields)), [
        { server_ids: ['server-a'] },
        { server_ids: ['server-b'] },
    ]);
});

test('STOP cancels the Restart interval and restores the button', async () => {
    const selected = [{ value: 'server-a', textContent: 'Server A | 192.0.2.10' }];
    const page = createRestartHarness({ selected, seconds: 5 });

    await page.buttonListeners.get('click')();
    page.stopButtonListeners.get('click')();

    assert.deepEqual(page.clearedIntervals, [1]);
    assert.equal(page.runtime.restartTimer, null);
    assert.equal(page.button.disabled, false);
    assert.equal(page.button.innerHTML, 'RESTART');
    assert.equal(page.buttonAttributes.has('aria-busy'), false);
});

test('Restart explains the DMS server-selection requirement when no server is selected', async () => {
    const page = createRestartHarness();

    await page.buttonListeners.get('click')();

    assert.deepEqual(page.alerts, [{ title: 'Oops', message: 'Please select at least one server' }]);
    assert.equal(page.env.servers.focused, true);
    assert.equal(page.requests.length, 0);
});

test('a failed Restart request stops the repeating timer and clears its result table', async () => {
    const selected = [{ value: 'server-a', textContent: 'Server A | 192.0.2.10' }];
    const page = createRestartHarness({ selected, seconds: 5 });

    await page.buttonListeners.get('click')();
    page.requests[0].deferred.resolve({ received: false, items: [], message: 'Request failed' });
    await new Promise((resolve) => setImmediate(resolve));

    assert.deepEqual(page.clearedIntervals, [1]);
    assert.equal(page.runtime.restartTimer, null);
    assert.deepEqual(page.status.added, ['d-none']);
    assert.equal(page.button.disabled, true, 'STOP remains available to restore the repeating control');
});

test('Schedule fans out immediately and on each interval, updates rows in response order, and STOP leaves requests in flight', async () => {
    const selected = [
        { value: 'server-a', textContent: 'Server A | 192.0.2.10' },
        { value: 'server-b', textContent: 'Server B | 192.0.2.11' },
    ];
    const page = createScheduleHarness({ selected, seconds: 1 });

    await page.buttonListeners.get('click')();

    assert.equal(page.requests.length, 2, 'each selected server receives an immediate singleton request');
    assert.deepEqual(page.requests.map(({ action, serverIds, values, queue, options }) => clone({ action, serverIds, values, queue, options })), [
        { action: 'schedule', serverIds: ['server-a'], values: ['*/*'], queue: 'domain', options: { render: false, commandText: 'schedule */*' } },
        { action: 'schedule', serverIds: ['server-b'], values: ['*/*'], queue: 'domain', options: { render: false, commandText: 'schedule */*' } },
    ]);
    assert.deepEqual(page.intervals.map(({ milliseconds }) => milliseconds), [1000]);
    assert.equal(page.runtime.scheduleTimer, 1);
    assert.equal(page.button.disabled, true);
    assert.equal(page.runtime.scheduleInFlight, 2);

    page.intervals[0].callback();

    assert.equal(page.requests.length, 4, 'the interval starts the next per-server requests without awaiting the previous run');
    assert.deepEqual(page.requests.slice(2).map(({ serverIds }) => clone(serverIds)), [['server-a'], ['server-b']]);
    page.stopButtonListeners.get('click')();

    assert.deepEqual(page.clearedIntervals, [1]);
    assert.equal(page.runtime.scheduleTimer, null);
    assert.equal(page.runtime.scheduleActive, false);
    assert.equal(page.button.disabled, false);
    assert.match(page.status.textContent, /4 request\(s\) are still in progress/);

    const order = [3, 0, 2, 1];
    for (const index of order) {
        const request = page.requests[index];
        const serverId = request.serverIds[0];
        const serverName = selected.find((server) => server.value === serverId).textContent;
        request.deferred.resolve({
            success: true,
            received: true,
            items: [{ id: serverId, name: serverName, result: '1 of 1 matching queues scheduled.', success: true }],
        });
        await new Promise((resolve) => setImmediate(resolve));
    }
    await new Promise((resolve) => setImmediate(resolve));
    await new Promise((resolve) => setImmediate(resolve));

    assert.equal(page.runtime.scheduleInFlight, 0);
    assert.equal(page.runtime.scheduleResponseCount, 4);
    assert.deepEqual(Array.from(page.runtime.scheduleResultsByServer.values()).map(({ id, run }) => ({ id, run })), [
        { id: 'server-a', run: 2 },
        { id: 'server-b', run: 1 },
    ]);
    assert.match(page.status.textContent, /4 server response\(s\) received/);
});

test('Schedule transport failure stops its interval as soon as a selected server fails', async () => {
    const selected = [
        { value: 'server-a', textContent: 'Server A | 192.0.2.10' },
        { value: 'server-b', textContent: 'Server B | 192.0.2.11' },
    ];
    const page = createScheduleHarness({ selected, seconds: 5 });

    await page.buttonListeners.get('click')();
    page.requests[1].deferred.resolve({ success: false, received: false, items: [], message: 'Request failed' });
    await new Promise((resolve) => setImmediate(resolve));

    assert.deepEqual(page.clearedIntervals, [1]);
    assert.equal(page.runtime.scheduleTimer, null);
    assert.equal(page.runtime.scheduleActive, false);
    assert.equal(page.runtime.scheduleInFlight, 1, 'the other server request remains in flight');
    assert.equal(page.button.disabled, false);
    assert.match(page.status.textContent, /Schedule stopped after a request failed/);

    page.requests[0].deferred.resolve({
        success: true,
        received: true,
        items: [{ id: 'server-a', name: 'Server A', result: '1 of 1 matching queues scheduled.', success: true }],
    });
    await new Promise((resolve) => setImmediate(resolve));

    assert.equal(page.runtime.scheduleInFlight, 0);
    assert.equal(page.runtime.scheduleResponseCount, 1);
});

test('one-shot Schedule sends one request per selected server and re-enables after a response', async () => {
    const selected = [{ value: 'server-a', textContent: 'Server A | 192.0.2.10' }];
    const page = createScheduleHarness({ selected, seconds: 0 });

    await page.buttonListeners.get('click')();

    assert.equal(page.intervals.length, 0);
    assert.equal(page.requests.length, 1);
    assert.deepEqual(page.requests[0].serverIds, ['server-a']);
    assert.equal(page.button.disabled, true);

    page.requests[0].deferred.resolve({
        success: true,
        received: true,
        items: [{ id: 'server-a', name: 'Server A', result: '1 of 1 matching queues scheduled.', success: true }],
    });
    await new Promise((resolve) => setImmediate(resolve));

    assert.equal(page.button.disabled, false);
    assert.equal(page.runtime.scheduleResponseCount, 1);
    assert.match(page.status.textContent, /One schedule run returned/);
});
