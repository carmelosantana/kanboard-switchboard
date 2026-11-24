import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { add, fakeDocument, FakeEvent, fakeTimers } from './fakedom.mjs';

const sources = ['switchboard-core.js', 'switchboard.js'].map(file =>
    readFileSync(new URL('../../Assets/js/' + file, import.meta.url), 'utf8'));
const providers = [
    { id: 'switchboard.tasks', title: 'Tasks', prefix: '#', order: 1 },
    { id: 'switchboard.projects', title: 'Projects', prefix: ':', order: 2 },
];
const reply = data => ({ ok: true, json: async () => data });
const page = (title, cursor = null) => reply({ entries: [{ title, url: '/' + title }], cursor });

async function searchUI(commands, fetch, config = {}) {
    const doc = fakeDocument();
    const root = add(doc.body, 'div', {
        id: 'switchboard-root',
        'data-commands-url': '/commands',
        'data-providers-url': '/providers',
        'data-search-url': '/search',
        ...config,
    });
    add(root, 'div', { role: 'status', 'data-slot': 'switchboard-status' });
    const timers = fakeTimers();
    const requests = [];
    const win = {
        ...timers, URL, AbortController,
        location: { href: 'https://kb.example/' },
        fetch: async (url, options) => {
            const request = new URL(url, win.location.href);
            requests.push({ url: request, options });
            if (request.pathname === '/commands') {
                return reply({ commands, recent: [] });
            }
            return fetch(request, options);
        },
    };
    for (const source of sources) {
        new Function('window', 'document', source)(win, doc);
    }
    doc.dispatchEvent(new FakeEvent('DOMContentLoaded'));
    const key = (key, flags) => doc.activeElement.dispatchEvent(new FakeEvent('keydown', { key, bubbles: true, ...flags }));
    const settle = () => new Promise(resolve => setImmediate(resolve));
    key('k', { ctrlKey: true });
    await settle();
    const dialog = root.querySelector('dialog');
    const input = dialog.querySelector('input');
    const options = () => dialog.querySelectorAll('li[role=option]');
    const type = value => {
        input.value = value;
        input.dispatchEvent(new FakeEvent('input'));
    };
    const results = () => options().map(option => option.textContent);
    const searchRequests = () => requests.filter(request => request.url.pathname === '/search');
    const tick = async ms => {
        timers.advance(ms);
        await settle();
    };
    const headers = () => dialog.querySelectorAll('li[role=presentation]').map(header => header.textContent);
    return { headers, options, results, type, key, tick, settle, requests, searchRequests };
}

function setup(search, commands = []) {
    return searchUI(commands, (url, options) => url.pathname === '/providers'
        ? reply({ providers }) : search(url, options), { 'data-project-id': '12', 'data-from-plugin': 'Example' });
}

test('search groups use the provider title and fall back to the id when the title is missing', async () => {
    for (const [provider, label] of [
        [providers[0], 'Tasks'],
        [{ id: 'switchboard.tasks', prefix: '#' }, 'switchboard.tasks'],
    ]) {
        const ui = await searchUI([], url => url.pathname === '/providers'
            ? reply({ providers: [provider] }) : page('Task A'));
        ui.type('#task');
        await ui.tick(250);

        assert.deepEqual(ui.headers(), [label]);
        assert.equal(ui.options()[0].getAttribute('aria-label'), `Task A, ${label}, opens page`, 'command and search rows: title, group, then hints');
    }
});

test('debounces typing, fans out the latest term and aborts outstanding requests on the next query', async () => {
    const ui = await setup(() => new Promise(() => {}));
    ui.type('ab');
    await ui.tick(100);
    ui.type('abc');
    await ui.tick(249);
    assert.equal(ui.searchRequests().length, 0);
    await ui.tick(1);

    const batch = ui.searchRequests();
    assert.deepEqual(batch.map(({ url }) => [url.searchParams.get('provider'), url.searchParams.get('term')]),
        providers.map(provider => [provider.id, 'abc']));
    assert.ok(batch.every(({ url, options }) => url.searchParams.get('limit') === '10'
        && url.searchParams.get('project_id') === '12' && !options.signal.aborted));

    ui.type('abcd');
    assert.ok(batch.every(({ options }) => options.signal.aborted));
    await ui.tick(250);
    assert.equal(ui.searchRequests().length, 4);
    assert.deepEqual(ui.requests.filter(({ url }) => url.pathname === '/providers')
        .map(({ url }) => url.searchParams.get('prefix')), ['']);
});

test('one rejection and one timeout each display an unavailable row while other results render', async () => {
    for (const fail of [() => Promise.reject(new Error('private')), () => new Promise(() => {})]) {
        const ui = await setup(url => url.searchParams.get('provider') === providers[0].id ? fail() : page('Project A'));
        ui.type('project');
        await ui.tick(250);
        await ui.tick(3000);
        assert.deepEqual([...ui.results()].sort(), ['Project A', 'Search unavailable']);
        const unavailable = ui.options().find(option => option.textContent === 'Search unavailable');
        assert.equal(unavailable.getAttribute('aria-disabled'), 'true');
    }
});

test('a later higher-priority provider appends below existing groups and commands stay first', async () => {
    let finishTasks;
    const tasks = new Promise(resolve => { finishTasks = resolve; });
    const command = { id: 'test.project', title: 'Project command', url: '/command', target: { kind: 'route' } };
    const ui = await setup(url => url.searchParams.get('provider') === providers[0].id ? tasks : page('Project A'), [command]);
    ui.type('project');
    await ui.tick(250);
    assert.deepEqual(ui.results(), ['Project command', 'Project A']);
    ui.key('End');
    finishTasks(page('Task A'));
    await ui.settle();
    assert.deepEqual(ui.results(), ['Project command', 'Project A', 'Task A']);
    assert.equal(ui.options()[1].getAttribute('aria-selected'), 'true');
});

test('Load more sends the cursor, appends results and ends on an empty page', async () => {
    const cursors = [];
    const ui = await setup(url => {
        const cursor = url.searchParams.get('cursor');
        cursors.push(cursor);
        if (cursor === 'second') {
            return page('Task B', 'end');
        }
        return cursor === 'end' ? reply({ entries: [], cursor: 'ignored' }) : page('Task A', 'second');
    });
    ui.type('#task');
    await ui.tick(250);
    assert.deepEqual(ui.results(), ['Task A', 'Load more']);
    ui.key('End');
    ui.key('Enter');
    await ui.settle();
    assert.deepEqual(ui.results(), ['Task A', 'Task B', 'Load more']);
    ui.key('End');
    ui.key('Enter');
    await ui.settle();
    assert.deepEqual(ui.results(), ['Task A', 'Task B']);
    assert.deepEqual(cursors, [null, 'second', 'end']);
});
