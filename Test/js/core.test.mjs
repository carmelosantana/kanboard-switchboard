import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const coreUrl = new URL('../../Assets/js/switchboard-core.js', import.meta.url);

function loadCore() {
    const window = {};
    vm.runInNewContext(readFileSync(coreUrl, 'utf8'), { window });
    return window.SwitchboardCore;
}

test('word starts outrank mid-word matches', () => {
    const { score } = loadCore();
    assert.ok(score('ct', 'Create task') > score('ct', 'Edit contact'));
});

for (const [label, query, title, expected] of [
    ['exact', 'create task', 'Create task', 1],
    ['incomplete', 'cat', 'cater', 0.99],
    ['space', 'ct', 'c t', 0.9 * 0.999],
    ...[...'-_/.:'].map(separator => ['separator ' + separator, 'ct', 'c' + separator + 't', 0.8 * 0.999]),
    ['mid-word', 'ct', 'cat', 0.17 * 0.999],
    ['multiple skipped characters', 'ct', 'coat', 0.17 * 0.999 ** 2],
    ['transposition', 'taks', 'task', 0.1],
    ['no match', 'cz', 'Create task', 0],
]) {
    test('score weights ' + label, () => {
        const { score } = loadCore();
        assert.ok(Math.abs(score(query, title) - expected) < 1e-12);
    });
}

test('score uses the best keyword alias', () => {
    const { score } = loadCore();
    assert.equal(score('new', 'Create task', ['newest', 'new']), 1);
    assert.equal(score('task', 'Task', ['tasks']), 1);
});

test('rank drops unmatched commands and uses frecency only for score ties', () => {
    const { rank } = loadCore();
    const commands = [
        { id: 'zulu', title: 'Zulu', keywords: ['go'] },
        { id: 'bravo', title: 'Bravo', keywords: ['go'] },
        { id: 'alpha', title: 'Alpha', keywords: ['go'] },
        { id: 'partial', title: 'Going' },
        { id: 'unmatched', title: 'Settings' },
    ];
    assert.deepEqual(Array.from(rank(commands, 'go', ['partial', 'zulu', 'bravo']), command => command.id),
        ['zulu', 'bravo', 'alpha', 'partial']);
    assert.deepEqual(Array.from(rank(commands, 'go', []), command => command.id),
        ['alpha', 'bravo', 'zulu', 'partial']);
});

test('rank puts recents before group and title order for an empty query', () => {
    const { rank } = loadCore();
    const commands = [
        { id: 'a', title: 'Alpha', group: 'Second' },
        { id: 'b', title: 'Bravo', group: 'First' },
        { id: 'c', title: 'Charlie', group: 'Second' },
        { id: 'd', title: 'Delta', group: 'First' },
        { id: 'e', title: 'Echo', group: 'First' },
    ];
    assert.deepEqual(Array.from(rank(commands, '', ['gone', 'c', 'a']), command => command.id),
        ['c', 'a', 'b', 'd', 'e']);
});

for (const [raw, mode, prefix, term] of [
    ['>   task', 'commands', '>', 'task'],
    ['# task', 'search', '#', 'task'],
    ['@ user', 'search', '@', 'user'],
    [': project', 'search', ':', 'project'],
    ['? help', 'help', '?', 'help'],
    ['  >x', 'all', '', '  >x'],
    ['task', 'all', '', 'task'],
    ['', 'all', '', ''],
]) {
    test('parseQuery parses ' + JSON.stringify(raw), () => {
        const { parseQuery } = loadCore();
        assert.deepEqual({ ...parseQuery(raw) }, { mode, prefix, term });
    });
}

for (const [term, required, input, complete] of [
    ['new task Buy milk', ['title'], { title: 'Buy milk' }, true],
    ['NEW   Buy milk', ['title'], { title: 'Buy milk' }, true],
    ['new task Buy milk', ['title', 'project'], { title: 'Buy milk' }, false],
    ['new tasks', ['title'], { title: 'tasks' }, true],
    ['newer task Buy milk', ['title'], {}, false],
    ['new task   ', ['title'], {}, false],
]) {
    test('quickFill handles ' + JSON.stringify([term, required]), () => {
        const { quickFill } = loadCore();
        const result = quickFill({
            title: 'New task',
            keywords: ['new'],
            input: {
                properties: {
                    priority: { type: 'string', enum: ['high'] },
                    project: { type: 'string', format: 'project' },
                    title: { type: 'string' },
                },
                required,
            },
        }, term);
        assert.deepEqual({ ...result, input: { ...result.input } }, { input, complete });
    });
}

for (const [label, event, expected] of [
    ['Ctrl+K', { ctrlKey: true }, 'palette'],
    ['Command+K', { metaKey: true }, 'palette'],
    ['Ctrl+K on an input', { ctrlKey: true, target: { tagName: 'INPUT' } }, 'palette'],
    ['Ctrl+K on a textarea', { ctrlKey: true, target: { tagName: 'TEXTAREA' } }, null],
    ['Ctrl+Shift+K on a textarea', { ctrlKey: true, shiftKey: true, key: 'K', target: { tagName: 'TEXTAREA' } }, 'commands'],
    ['Command+K on editable content', { metaKey: true, target: { isContentEditable: true } }, null],
    ['Command+Shift+K on editable content', { metaKey: true, shiftKey: true, key: 'K', target: { isContentEditable: true } }, 'commands'],
    ['Ctrl+Alt+Shift+K', { ctrlKey: true, altKey: true, shiftKey: true }, null],
    ['bare K', {}, null],
    ['Ctrl+J', { ctrlKey: true, key: 'j' }, null],
]) {
    test('shouldOpen handles ' + label, () => {
        const { shouldOpen } = loadCore();
        assert.equal(shouldOpen({ key: 'k', ...event }), expected);
    });
}

for (const [label, event, enabled, expected] of [
    ['plain slash', {}, true, true],
    ['disabled setting', {}, false, false],
    ...['INPUT', 'TEXTAREA', 'SELECT'].map(tagName => [tagName, { target: { tagName } }, true, false]),
    ['editable content', { target: { isContentEditable: true } }, true, false],
    ...['ctrlKey', 'metaKey', 'altKey', 'shiftKey'].map(modifier => [modifier, { [modifier]: true }, true, false]),
    ['another key', { key: '?' }, true, false],
]) {
    test('shouldFocusSearch handles ' + label, () => {
        const { shouldFocusSearch } = loadCore();
        assert.equal(shouldFocusSearch({ key: '/', ...event }, enabled), expected);
    });
}

test('mergeGroups appends late providers and replaces repeat answers in place', () => {
    const { mergeGroups } = loadCore();
    const order = ['tasks', 'projects', 'users'];
    const users = { providerId: 'users', entries: ['first answer'] };
    const tasks = { providerId: 'tasks', entries: ['late answer'] };
    const updatedUsers = { providerId: 'users', entries: ['updated answer'] };
    const current = Object.freeze([users]);
    const merged = mergeGroups(current, tasks, order);
    assert.deepEqual(Array.from(merged), [users, tasks]);
    assert.deepEqual(Array.from(mergeGroups(merged, updatedUsers, order)), [updatedUsers, tasks]);
    assert.deepEqual(Array.from(merged), [users, tasks]);
});
