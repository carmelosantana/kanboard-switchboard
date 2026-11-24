import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { add, fakeDocument, FakeEvent, fakeTimers } from './fakedom.mjs';

const sources = ['switchboard-core.js', 'switchboard.js'].map(file =>
    readFileSync(new URL('../../Assets/js/' + file, import.meta.url), 'utf8'));

const markup = '<img src=x onerror=alert(1)>';
const handler = { id: 'test.handler', title: 'Handler', target: { kind: 'handler' } };
const schema = properties => ({ type: 'object', properties, required: Object.keys(properties) });
const string = { type: 'string' };
const response = (data, status = 200) => ({ ok: status < 400, status, json: async () => data });

async function setup(command, responses, config) {
    const posts = [];
    const doc = fakeDocument();
    const root = add(doc.body, 'div', {
        id: 'switchboard-root',
        'data-commands-url': '/commands',
        'data-providers-url': '/providers',
        'data-search-url': '/search',
        'data-run-url': '/run',
        'data-csrf': 'csrf-value',
        'data-form-csrf': 'form-csrf-value',
        ...config,
    });
    const status = add(root, 'div', { role: 'status', 'data-slot': 'switchboard-status' });
    const toastSlot = add(root, 'div', { role: 'status', 'data-slot': 'switchboard-toast' });
    const timers = fakeTimers();
    const requests = [];
    const actions = [];
    const createElement = doc.createElement;
    doc.createElement = tag => {
        const node = createElement(tag);
        if (tag === 'form') {
            node.submit = () => actions.push(['submit', node.getAttribute('method'), node.getAttribute('action'),
                Object.fromEntries(node.children.map(field => [field.getAttribute('name'), field.getAttribute('value')]))]);
        }
        return node;
    };
    async function fetch(href, options) {
        const url = new URL(href, win.location.href);
        requests.push({ url, options });
        if (url.pathname === '/commands') {
            return response({ commands: [command], recent: [] });
        }
        if (url.pathname === '/providers') {
            return response({ providers: [
                { id: 'test.projects', prefix: ':', title: 'Other projects' },
                { id: 'switchboard.projects', prefix: ':', title: 'Projects' },
            ] });
        }
        if (url.pathname === '/search') {
            assert.equal(url.searchParams.get('provider'), 'switchboard.projects');
            assert.equal(url.searchParams.get('prefix'), ':');
            return response({ entries: [{ id: 42, title: 'Project Forty-Two', url: '/board/42' }], cursor: null });
        }
        assert.equal(url.pathname, '/run');
        assert.equal(options.method, 'POST');
        posts.push(Object.fromEntries(options.body));
        return responses.shift();
    }
    const win = {
        ...timers, URL, AbortController, FormData, fetch,
        location: { href: 'https://kb.example/', assign: url => actions.push(['assign', url]) },
        navigator: {},
        KB: { modal: { open: (...args) => actions.push(['modal', ...args]) } },
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
    const type = value => {
        input.value = value;
        input.dispatchEvent(new FakeEvent('input'));
    };
    const results = () => dialog.querySelectorAll('li[role=option]').map(option => option.textContent);
    const tick = async ms => {
        timers.advance(ms);
        await settle();
    };
    return { doc, root, status, toastSlot, requests, actions, posts, dialog, input, type, key, settle, results, tick };
}

test('confirmation uses literal text, resubmits with its token, and shows a literal toast with undo', async () => {
    const ui = await setup(handler, [
        response({ confirm: { message: markup, token: 'confirm-value' } }),
        response({ type: 'toast', message: markup, undo: { id: 'test.undo', input: { item: '42' } } }),
        response({ type: 'toast', message: 'Undone' }),
    ]);
    ui.key('Enter');
    await ui.settle();
    assert.ok(ui.dialog.textContent.includes(markup));
    assert.ok(ui.dialog.textContent.includes('Press Enter again to confirm'));
    assert.equal(ui.doc.querySelectorAll('img').length, 0);
    assert.deepEqual(ui.posts, [{ id: handler.id, input: '{}', csrf_token: 'csrf-value' }]);

    ui.key('Enter', { repeat: true });
    await ui.settle();
    assert.equal(ui.posts.length, 1);
    ui.key('Enter');
    await ui.settle();
    assert.equal(ui.posts[1].confirm_token, 'confirm-value');
    const undo = ui.doc.querySelectorAll('button').find(button => button.textContent === 'Undo');
    assert.equal(undo.parentNode, ui.toastSlot, 'the toast fills the persistent live region');
    assert.equal(ui.doc.querySelectorAll('[data-slot=switchboard-toast]').length, 1, 'no new toast region per toast');
    assert.equal(ui.toastSlot.querySelectorAll('[role=status]').length, 0, 'no live region nested in the slot');
    assert.ok(ui.toastSlot.textContent.includes(markup));
    assert.equal(ui.doc.querySelectorAll('img').length, 0);
    undo.dispatchEvent(new FakeEvent('click'));
    await ui.settle();
    assert.deepEqual(ui.posts[2], { id: 'test.undo', input: '{"item":"42"}', csrf_token: 'csrf-value' });
    assert.ok(ui.toastSlot.textContent.includes('Undone'));
    await ui.tick(5000);
    assert.equal(ui.toastSlot.textContent, '');
    assert.equal(ui.toastSlot.parentNode, ui.root, 'the slot stays for the next message');
});

test('a text result renders a paragraph as literal text', async () => {
    const ui = await setup(handler, [response({ type: 'text', body: markup })]);
    ui.key('Enter');
    await ui.settle();
    assert.ok(ui.dialog.querySelectorAll('p').some(p => p.textContent === markup));
    assert.equal(ui.doc.querySelectorAll('img').length, 0);
});

test('post targets submit a real POST form with the form CSRF token and the collected input', async () => {
    const ui = await setup({
        ...handler, target: { kind: 'post' }, url: '/legacy-action', input: schema({ title: string }),
    }, []);
    ui.key('Enter');
    ui.type('Buy milk');
    ui.key('Enter');
    assert.deepEqual(ui.actions, [['submit', 'post', '/legacy-action', { title: 'Buy milk', csrf_token: 'form-csrf-value' }]]);
});

test('three steps collect a string, an enum and a formatted project id', async () => {
    const ui = await setup({
        ...handler,
        input: schema({ title: { ...string, title: 'Task title' }, kind: { ...string, enum: ['Work', 'Home'] },
            project: { ...string, format: 'project' } }),
    }, [response({ type: 'text', body: 'Done' })], { 'data-project-id': '12', 'data-from-plugin': 'Example' });
    ui.key('Enter');
    assert.equal(ui.input.getAttribute('placeholder'), 'Task title');
    assert.ok(ui.dialog.textContent.includes('step 1/3'));
    ui.type('One');
    ui.key('Enter');
    assert.equal(ui.input.getAttribute('placeholder'), 'kind');
    assert.ok(ui.dialog.textContent.includes('step 2/3'));
    assert.deepEqual(ui.results(), ['Work', 'Home']);
    ui.key('ArrowDown');
    ui.key('Enter');
    ui.type('forty');
    await ui.tick(250);
    assert.deepEqual(ui.results(), ['Project Forty-Two']);
    ui.key('Enter');
    await ui.settle();
    assert.deepEqual(JSON.parse(ui.posts[0].input), { title: 'One', kind: 'Home', project: '42' });
    const run = ui.requests.find(({ url }) => url.pathname === '/run').url;
    assert.equal(run.searchParams.get('project_id'), '12');
    assert.equal(run.searchParams.get('from_plugin'), 'Example');
});

test('Backspace on an empty step restores the previous value for editing', async () => {
    const ui = await setup({ ...handler, input: schema({ first: string, second: string }) }, []);
    ui.key('Enter');
    ui.type('Previous value');
    ui.key('Enter');
    assert.equal(ui.input.getAttribute('placeholder'), 'second');
    ui.key('Backspace');
    assert.equal(ui.input.getAttribute('placeholder'), 'first');
    assert.equal(ui.input.value, 'Previous value');
});

test('quick fill makes a command discoverable and skips its steps', async () => {
    const ui = await setup({
        ...handler, title: 'New task', input: schema({ title: string }),
    }, [response({ type: 'text', body: 'Done' })]);
    ui.type('new task Buy milk');
    assert.deepEqual(ui.results(), ['New task']);
    ui.key('Enter');
    await ui.settle();
    assert.deepEqual(JSON.parse(ui.posts[0].input), { title: 'Buy milk' });
});

test('run errors announce the frozen code inside and outside the dialog', async () => {
    const ui = await setup(handler, [response({ error: 'invalid input', field: 'title' }, 422)]);
    ui.key('Enter');
    await ui.settle();
    assert.equal(ui.status.textContent, 'invalid input');
    assert.ok(ui.dialog.querySelectorAll('[role=status]').some(node => node.textContent === 'invalid input'));
    ui.type('>Handler');
    assert.ok(!ui.dialog.textContent.includes('invalid input'));
});

test('step-up and navigate results assign their URLs, and modal results open Kanboard modals', async () => {
    for (const [reply, action] of [
        [response({ error: 'step up required', url: '/step-up' }, 428), ['assign', '/step-up']],
        [response({ type: 'navigate', url: '/next' }), ['assign', '/next']],
        [response({ type: 'modal', url: '/form', size: 'large' }), ['modal', '/form', 'large']],
    ]) {
        const ui = await setup(handler, [reply]);
        ui.key('Enter');
        await ui.settle();
        assert.deepEqual(ui.actions, [action]);
    }
});

test('list results present at most ten links and allow keyboard activation', async () => {
    const items = Array.from({ length: 12 }, (_, index) => ({ title: 'Item ' + index, url: '/item/' + index }));
    const ui = await setup(handler, [response({ type: 'list', items })]);
    ui.key('Enter');
    await ui.settle();
    assert.deepEqual(ui.dialog.querySelectorAll('a').map(link => link.getAttribute('href')),
        items.slice(0, 10).map(item => item.url));
    ui.key('End');
    ui.key('Enter');
    assert.deepEqual(ui.actions, [['assign', '/item/9']]);
});
