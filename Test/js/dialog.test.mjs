import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { add, fakeDocument, FakeEvent, fakeTimers } from './fakedom.mjs';

const core = readFileSync(new URL('../../Assets/js/switchboard-core.js', import.meta.url), 'utf8');
const route = (id, title, group = 'Kanboard') => ({ id, title, group, target: { kind: 'route' }, url: '/' + id });
const defaults = [route('example.alpha', 'Alpha'), route('example.beta', 'Beta'), route('example.gamma', 'Gamma', 'Plugin')];
const ok = (commands, recent = []) => ({ ok: true, json: async () => ({ commands, recent }) });

function setup(responses = [ok(defaults)], singleKeys = '1', withRoot = true) {
    const doc = fakeDocument();
    const header = add(doc.body, 'header');
    const menus = add(header, 'div', { class: 'menus-container' });
    const creationMenu = add(menus, 'div', { class: 'dropdown header-creation-menu' });
    add(creationMenu, 'a', { class: 'dropdown-menu-link-icon' });
    const userMenu = add(menus, 'div', { class: 'dropdown' });
    add(userMenu, 'a', { class: 'dropdown-menu-link-icon' });
    const search = add(doc.body, 'input', { id: 'form-search' });
    const timers = fakeTimers();
    const requests = [];
    const actions = [];
    let status;
    if (withRoot) {
        const root = add(doc.body, 'div', {
            id: 'switchboard-root',
            'data-page-url': '/switchboard',
            'data-commands-url': '/commands?keep=yes',
            'data-used-url': '/used',
            'data-csrf': 'csrf-value',
            'data-project-id': '12',
            'data-task-id': '34',
            'data-from-controller': 'TaskViewController',
            'data-from-action': 'show',
            'data-from-plugin': 'Example',
            'data-single-keys': singleKeys,
        });
        status = add(root, 'div', { role: 'status', 'data-slot': 'switchboard-status' });
    }
    const win = {
        ...timers,
        KeyboardEvent: FakeEvent,
        URL,
        FormData,
        fetch: async url => {
            requests.push(new URL(url));
            return responses.shift();
        },
        navigator: {
            sendBeacon(url, data) { actions.push(['beacon', url, Object.fromEntries(data)]); },
        },
        location: { href: 'https://kb.example/board', assign: url => actions.push(['assign', url]) },
        open: (...args) => actions.push(['open', ...args]),
        KB: { modal: { open: (...args) => actions.push(['modal', ...args, dialog().open]) } },
    };
    const dialog = () => doc.querySelector('dialog[data-slot=switchboard]');
    const input = () => dialog().querySelector('[role=combobox]');
    const options = () => dialog().querySelectorAll('[role=option]');
    const trigger = () => doc.querySelector('a[data-slot=switchboard-trigger]');
    const key = (key, flags = {}, target = doc.activeElement) => {
        const event = new FakeEvent('keydown', { key, bubbles: true, ...flags });
        target.dispatchEvent(event);
        return event;
    };
    const type = value => {
        input().value = value;
        input().dispatchEvent(new FakeEvent('input'));
    };
    const settled = () => new Promise(resolve => setImmediate(resolve));
    const open = async () => {
        key('k', { ctrlKey: true });
        await settled();
    };
    for (const source of [core, readFileSync(new URL('../../Assets/js/switchboard.js', import.meta.url), 'utf8')]) {
        new Function('window', 'document', source)(win, doc);
    }
    doc.dispatchEvent(new FakeEvent('DOMContentLoaded'));
    return { doc, menus, search, timers, requests, actions, status, win, dialog, input, options, trigger, key, type, open };
}

test('mount is lazy, inserts the trigger before the user menu and opens once per page', async () => {
    const ui = setup();
    assert.equal(typeof ui.win.SwitchboardUI.mount, 'function');
    assert.deepEqual(ui.menus.children.map(node => node.getAttribute('class') ?? node.getAttribute('data-slot')),
        ['dropdown header-creation-menu', 'switchboard-trigger', 'dropdown']);
    assert.equal(ui.trigger().getAttribute('href'), '/switchboard');
    assert.equal(ui.trigger().getAttribute('aria-label'), 'Switchboard (Ctrl+K)');
    assert.equal(ui.dialog(), null);
    assert.equal(ui.requests.length, 0);
    assert.equal(ui.key('k', { ctrlKey: true }).defaultPrevented, true);
    assert.equal(ui.dialog().modalCalls, 1);
    assert.equal(ui.doc.activeElement, ui.input());
    ui.key('Escape');
    ui.trigger().focus();
    const click = new FakeEvent('click');
    ui.trigger().dispatchEvent(click);
    assert.equal(click.defaultPrevented, true);
    assert.equal(ui.dialog().modalCalls, 2);
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(ui.requests.length, 1);
});

test('without a root, no trigger or hotkeys are installed', () => {
    const ui = setup([], '1', false);
    assert.equal(ui.trigger(), null);
    assert.equal(ui.key('k', { ctrlKey: true }).defaultPrevented, false);
    assert.equal(ui.doc.listeners.get('keydown'), undefined);
});

test('Ctrl+K in a textarea leaves the editor shortcut alone, while Ctrl+Shift+K opens commands', () => {
    const ui = setup();
    const textarea = add(ui.doc.body, 'textarea');
    textarea.focus();
    assert.equal(ui.key('k', { ctrlKey: true }).defaultPrevented, false);
    assert.equal(ui.dialog(), null);
    assert.equal(ui.key('K', { ctrlKey: true, shiftKey: true }).defaultPrevented, true);
    assert.equal(ui.input().value, '>');
});

test('dialog and combobox are named, with a flat accessible list of options', async () => {
    const ui = setup();
    await ui.open();
    const label = ui.doc.getElementById(ui.dialog().getAttribute('aria-labelledby'));
    assert.equal(label.textContent, 'Switchboard');
    const listbox = ui.dialog().querySelector('ul[role=listbox]');
    const input = ui.input();
    assert.equal(input.getAttribute('aria-autocomplete'), 'list');
    assert.equal(input.getAttribute('aria-expanded'), 'true');
    assert.equal(input.getAttribute('aria-controls'), listbox.id);
    assert.equal(input.getAttribute('aria-owns'), listbox.id);
    assert.equal(input.getAttribute('aria-activedescendant'), ui.options()[0].id);
    assert.equal(ui.dialog().querySelectorAll('[role=group]').length, 0);
    assert.deepEqual(listbox.querySelectorAll('li[role=presentation]').map(node => node.textContent), ['Kanboard', 'Plugin']);
    assert.equal(ui.options()[0].getAttribute('aria-label'), 'Alpha, Kanboard, opens page');
});

test('core command options show shortcut keys after the title and include them in their accessible names', async () => {
    const ui = setup([ok([
        {
            id: 'switchboard.core.new-task',
            title: 'New task',
            group: 'Kanboard',
            shortcut: 'n',
            target: { kind: 'modal' },
        },
        {
            id: 'switchboard.core.view-overview',
            title: 'View overview',
            group: 'Kanboard',
            shortcut: 'v+o',
            target: { kind: 'key', key: 'v+o' },
        },
    ])]);
    await ui.open();
    const [newTask, overview] = ui.options();
    assert.equal(newTask.querySelector('kbd[data-slot=switchboard-shortcut]')?.textContent, 'n');
    assert.equal(newTask.getAttribute('aria-label'), 'New task, Kanboard, shortcut n');
    assert.equal(overview.querySelector('kbd[data-slot=switchboard-shortcut]')?.textContent, 'v o');
    assert.equal(overview.getAttribute('aria-label'), 'View overview, Kanboard, shortcut v o');
});

test('ArrowDown, End, Home and ArrowUp move the active option', async () => {
    const ui = setup();
    await ui.open();
    for (const [key, index] of [['ArrowDown', 1], ['End', 2], ['Home', 0], ['ArrowUp', 0]]) {
        assert.equal(ui.key(key).defaultPrevented, true);
        assert.equal(ui.input().getAttribute('aria-activedescendant'), ui.options()[index].id);
        assert.deepEqual(ui.options().map(option => option.getAttribute('aria-selected')),
            defaults.map((_, position) => String(position === index)));
    }
});

test('Escape closes the dialog, stops propagation and returns focus', async () => {
    const ui = setup();
    const opener = add(ui.doc.body, 'button');
    opener.focus();
    await ui.open();
    const escape = ui.key('Escape');
    assert.equal(ui.dialog().open, false);
    assert.equal(ui.doc.activeElement, opener);
    assert.equal(escape.defaultPrevented, true);
    assert.equal(escape.propagationStopped, true);
});

test('Enter records a route command before navigating', async () => {
    const ui = setup();
    await ui.open();
    assert.equal(ui.key('Enter').defaultPrevented, true);
    const [kind, url, form] = ui.actions[0];
    assert.equal(kind, 'beacon');
    assert.equal(new URL(url, ui.win.location.href).pathname, '/used');
    assert.deepEqual(form, { id: 'example.alpha', csrf_token: 'csrf-value' });
    assert.deepEqual(ui.actions[1], ['assign', '/example.alpha']);
});

test('Meta+Enter records a route command before opening a new tab', async () => {
    const ui = setup();
    await ui.open();
    ui.key('Enter', { metaKey: true });
    assert.equal(ui.actions[0][0], 'beacon');
    assert.deepEqual(ui.actions[1], ['open', '/example.alpha', '_blank', 'noopener']);
});

test('modal command records usage and closes the palette before calling Kanboard', async () => {
    const ui = setup([ok([{ ...defaults[0], target: { kind: 'modal', size: 'large' } }])]);
    await ui.open();
    ui.key('Enter');
    assert.equal(ui.actions[0][0], 'beacon');
    assert.deepEqual(ui.actions[1], ['modal', '/example.alpha', 'large', false]);
});

test('key command records usage and dispatches v then o on the document', async () => {
    const ui = setup([ok([{ ...defaults[0], target: { kind: 'key', key: 'v+o' } }])]);
    await ui.open();
    ui.doc.addEventListener('keydown', event => {
        if (event.target === ui.doc) {
            ui.actions.push(['key', event.key, event.bubbles, ui.dialog().open]);
        }
    });
    ui.key('Enter');
    assert.equal(ui.actions[0][0], 'beacon');
    assert.deepEqual(ui.actions.slice(1), [['key', 'v', true, false], ['key', 'o', true, false]]);
});

test('handler commands never send a beacon and unavailable recent ids do not render', async () => {
    const handler = { id: 'example.handler', title: 'Handler', group: 'Kanboard', target: { kind: 'handler' } };
    const ui = setup([ok([...defaults, handler], ['example.missing', handler.id])]);
    await ui.open();
    assert.deepEqual(ui.options().map(option => option.textContent), ['Handler', 'Alpha', 'Beta', 'Gamma']);
    ui.key('Enter');
    assert.deepEqual(ui.actions, []);
});

test('403 retries once with route context and unrelated parameters intact', async () => {
    const ui = setup([{ status: 403 }, { status: 403 }]);
    await ui.open();
    assert.deepEqual(ui.requests.map(url => Object.fromEntries(url.searchParams)), [
        { keep: 'yes', from_controller: 'TaskViewController', from_action: 'show', from_plugin: 'Example', project_id: '12', task_id: '34' },
        { keep: 'yes', from_controller: 'TaskViewController', from_action: 'show', from_plugin: 'Example' },
    ]);
});

test('the status debounces changes, leaves stable counts alone and toggles repeated announcements', async () => {
    const ui = setup();
    await ui.open();
    ui.timers.advance(249);
    assert.equal(ui.status.textContent, '');
    ui.timers.advance(1);
    assert.equal(ui.status.textContent, '3 results');
    ui.type('>Beta');
    ui.timers.advance(249);
    assert.equal(ui.status.textContent, '3 results');
    ui.timers.advance(1);
    assert.equal(ui.status.textContent, '1 results');
    ui.type('>Alpha');
    ui.timers.advance(250);
    assert.equal(ui.status.textContent, '1 results');
    for (const expected of ['1 results\u00a0', '1 results']) {
        ui.type('');
        ui.type('>Alpha');
        ui.timers.advance(250);
        assert.equal(ui.status.textContent, expected);
    }
});

test('help lists the prefixes and keys as non-actionable options', async () => {
    const ui = setup();
    await ui.open();
    ui.type('?');
    const options = ui.options();
    assert.ok(options.length >= 8);
    for (const text of ['>', '#', '@', ':', '?', 'Ctrl', 'Shift', '/']) {
        assert.ok(options.some(option => option.textContent.includes(text)), text);
    }
    assert.ok(options.every(option => option.getAttribute('aria-disabled') === 'true'));
    ui.key('Enter');
    assert.deepEqual(ui.actions, []);
});

test('/ focuses core search unless single keys are disabled', () => {
    const enabled = setup();
    assert.equal(enabled.key('/').defaultPrevented, true);
    assert.equal(enabled.doc.activeElement, enabled.search);
    const disabled = setup([], '0');
    assert.equal(disabled.key('/').defaultPrevented, false);
    assert.equal(disabled.doc.activeElement, disabled.doc.body);
});

test('/ prefers SuiteUI search and does nothing with no search target', () => {
    const ui = setup();
    const search = add(ui.doc.body, 'input', { 'data-slot': 'search' });
    assert.equal(ui.key('/').defaultPrevented, true);
    assert.equal(ui.doc.activeElement, search);
    search.removeAttribute('data-slot');
    ui.search.removeAttribute('id');
    assert.equal(ui.key('/', {}, ui.doc.body).defaultPrevented, false);
});
