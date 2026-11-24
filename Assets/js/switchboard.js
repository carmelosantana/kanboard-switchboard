(function () {
    'use strict';

    const mounted = new WeakSet();
    const placeholder = 'Type a command or search…';
    const selectors = {
        project: { prefix: ':', providerId: 'switchboard.projects' },
        task: { prefix: '#', providerId: 'switchboard.tasks' },
        user: { prefix: '@', providerId: 'switchboard.users' },
    };
    const jsonRequest = {
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    };
    const help = [
        '> Commands', '# Tasks', '@ Users', ': Projects', '? Help',
        'Ctrl/⌘+K Open Switchboard', 'Ctrl/⌘+Shift+K Open commands',
        '/ Focus page search', '↑/↓ and Home/End Select a result', 'Enter Run selected command',
        'Ctrl/⌘+Enter Open page in a new tab', 'Esc Close Switchboard',
    ];

    function mount(doc, win) {
        const root = doc.getElementById('switchboard-root');
        if (!root || mounted.has(doc)) {
            return;
        }
        mounted.add(doc);

        const core = win.SwitchboardCore;
        const config = root.dataset;
        const status = root.querySelector('[data-slot=switchboard-status]');
        let dialog, query, listbox, empty, detail, errorStatus, opener;
        let commands = [], recent = [], commandRequest;
        let rows = [], selection = -1;
        let announcementTimer, resultCount;
        const providerLists = new Map();
        let search, searchTimer;
        let steps, confirmation, result, pendingRun;
        let toastSlot, toastTimer;

        function endpoint(path, params = {}) {
            const url = new win.URL(path, win.location.href);
            for (const [name, value] of Object.entries({
                from_controller: config.fromController,
                from_action: config.fromAction,
                from_plugin: config.fromPlugin,
                project_id: config.projectId,
                task_id: config.taskId,
            })) {
                if (value) {
                    url.searchParams.set(name, value);
                }
            }
            for (const [name, value] of Object.entries(params)) {
                url.searchParams.set(name, value);
            }
            return url;
        }

        function canOpen(url, sameOrigin = false) {
            if (typeof url !== 'string' || !url.trim()) {
                return false;
            }
            try {
                const destination = new win.URL(url, win.location.href);
                return ['http:', 'https:'].includes(destination.protocol)
                    && (!sameOrigin || destination.origin === new win.URL(win.location.href).origin);
            } catch {
                return false;
            }
        }

        function showError(code) {
            win.clearTimeout(announcementTimer);
            resultCount = undefined;
            status.textContent = code;
            errorStatus.textContent = code;
        }

        function element(tag, attrs, text = '') {
            const node = doc.createElement(tag);
            for (const [name, value] of Object.entries(attrs)) {
                node.setAttribute(name, value);
            }
            node.textContent = text;
            return node;
        }

        function announce(count) {
            if (count === resultCount) {
                return;
            }
            resultCount = count;
            win.clearTimeout(announcementTimer);
            announcementTimer = win.setTimeout(() => {
                const message = count + ' results';
                status.textContent = status.textContent === message ? message + '\u00a0' : message;
            }, 250);
        }

        function select(index, scroll = false) {
            const previous = rows[selection]?.option;
            previous?.setAttribute('aria-selected', 'false');
            selection = Math.max(-1, Math.min(rows.length - 1, index));
            const current = rows[selection]?.option;
            if (current) {
                current.setAttribute('aria-selected', 'true');
                query.setAttribute('aria-activedescendant', current.id);
                if (scroll) {
                    current.scrollIntoView({ block: 'nearest' });
                }
            } else {
                query.removeAttribute('aria-activedescendant');
            }
        }

        function linkRow(entry) {
            const url = canOpen(entry.url) ? entry.url : null;
            return {
                title: entry.title,
                url,
                action: url ? event => navigate(url, event) : undefined,
            };
        }

        function stepRows() {
            const [name, property] = steps.fields[steps.index];
            const options = property.enum?.filter(value => value.toLowerCase().includes(query.value.toLowerCase()))
                .map(value => ({ title: value, action: () => advanceStep(value) })) ?? [];
            if ((property.enum || property.format) && !steps.command.input.required?.includes(name)) {
                options.push({ title: 'Skip', action: () => advanceStep() });
            }
            return options;
        }

        function searchRows() {
            return (search?.groups ?? []).flatMap(group => {
                const { provider, entries, cursor, failed } = group;
                const options = steps
                    ? entries.filter(entry => entry.id != null).map(entry => ({
                        title: entry.title, action: () => advanceStep(String(entry.id), entry.title),
                    }))
                    : entries.map(linkRow);
                if (failed) {
                    options.push({ title: 'Search unavailable' });
                } else if (cursor !== null) {
                    const batch = search;
                    options.push({ title: 'Load more', action: () => loadMore(batch, group) });
                }
                const title = provider.title ?? provider.id;
                return options.map(option => ({ ...option, group: title, groupId: provider.id }));
            });
        }

        function commandRows(term) {
            const ranked = core.rank(commands, term, recent);
            const rankedIds = new Set(ranked.map(command => command.id));
            const quick = commands.filter(command => command.input && !rankedIds.has(command.id)
                && Object.keys(core.quickFill(command, term).input).length);
            return [...quick, ...ranked].map(command => ({
                title: command.title,
                group: command.group,
                shortcut: command.shortcut?.replaceAll('+', ' ').trim(),
                leavesPage: command.target.kind === 'route',
                action: event => activate(command, event, term),
            }));
        }

        function render(preserveSelection = false) {
            const { mode, term } = core.parseQuery(query.value);
            let results = [];
            if (result?.type === 'list') {
                results = result.items.slice(0, 10).map(linkRow);
            } else if (steps) {
                results = [...searchRows(), ...stepRows()];
            } else if (!confirmation && !result) {
                if (mode === 'help') {
                    results = help.map(title => ({ title }));
                } else {
                    results = [...(mode === 'search' ? [] : commandRows(term)), ...searchRows()];
                }
            }
            let previousGroupId;
            listbox.replaceChildren();
            const previous = preserveSelection ? selection : -1;
            rows = results.map((row, index) => {
                const groupId = row.groupId ?? row.group;
                if (row.group && groupId !== previousGroupId) {
                    previousGroupId = groupId;
                    listbox.append(element('li', { role: 'presentation' }, row.group));
                }
                const label = [
                    row.title,
                    row.groupId ? '' : row.group,
                    row.url || row.leavesPage ? 'opens page' : '',
                    row.shortcut ? 'shortcut ' + row.shortcut : '',
                    row.groupId ? row.group : '',
                ].filter(Boolean).join(', ');
                const option = element('li', {
                    id: 'switchboard-option-' + index,
                    role: 'option',
                    'aria-label': label,
                    'aria-selected': 'false',
                }, row.url ? '' : row.title + (row.shortcut ? ' ' : ''));
                if (row.url) {
                    option.append(element('a', { href: row.url, tabindex: '-1' }, row.title));
                }
                if (row.shortcut) {
                    option.append(element('kbd', { 'data-slot': 'switchboard-shortcut' }, row.shortcut));
                }
                if (row.action) {
                    option.addEventListener('click', event => {
                        event.preventDefault();
                        if (!pendingRun) {
                            row.action(event);
                        }
                    });
                } else {
                    option.setAttribute('aria-disabled', 'true');
                }
                listbox.append(option);
                return { option, action: row.action };
            });
            selection = -1;
            select(results.length ? Math.max(0, previous) : -1);
            const showEmpty = !results.length && !confirmation && !result
                && (!steps || steps.fields[steps.index][1].enum || steps.fields[steps.index][1].format);
            empty.textContent = showEmpty ? (mode === 'commands' ? 'No matching commands' : 'No results') : '';
            if (!confirmation && !result && !steps) {
                announce(results.length);
            }
        }

        function stopSearch() {
            win.clearTimeout(searchTimer);
            search?.controller.abort();
            search = null;
        }

        function updateQuery() {
            stopSearch();
            if (errorStatus.textContent) {
                errorStatus.textContent = status.textContent = '';
            }
            render();
            const { mode, prefix, term } = core.parseQuery(query.value);
            const property = steps?.fields[steps.index][1];
            const selector = property?.format && !property.enum ? selectors[property.format] : null;
            if (!config.providersUrl || !config.searchUrl || confirmation || result
                || !(selector || (!steps && (mode === 'search' || (mode === 'all' && term.trim()))))) {
                return;
            }
            const batch = {
                prefix: selector?.prefix ?? prefix,
                term: selector ? query.value : term,
                providerId: selector?.providerId,
                controller: new win.AbortController(),
                groups: [],
            };
            search = batch;
            searchTimer = win.setTimeout(() => fanOut(batch), 250);
        }

        function listProviders(prefix) {
            if (!providerLists.has(prefix)) {
                const request = win.fetch(endpoint(config.providersUrl, { prefix }).href, jsonRequest)
                    .then(response => {
                        if (!response.ok) {
                            throw new Error();
                        }
                        return response.json();
                    })
                    .then(data => data.providers)
                    .catch(() => {
                        providerLists.delete(prefix);
                        return [];
                    });
                providerLists.set(prefix, request);
            }
            return providerLists.get(prefix);
        }

        async function searchPage(batch, provider, cursor) {
            const params = { provider: provider.id, prefix: batch.prefix, term: batch.term, limit: '10' };
            if (cursor !== null) {
                params.cursor = cursor;
            }
            const signal = batch.controller.signal;
            const controller = new win.AbortController();
            const abort = () => controller.abort();
            signal.addEventListener('abort', abort, { once: true });
            const timeout = win.setTimeout(abort, 3000);
            try {
                const cancelled = new Promise((_, reject) => {
                    controller.signal.addEventListener('abort', () => reject(new Error()), { once: true });
                });
                const request = win.fetch(endpoint(config.searchUrl, params).href, {
                    ...jsonRequest, signal: controller.signal,
                }).then(response => {
                    if (!response.ok) {
                        throw new Error();
                    }
                    return response.json();
                });
                const page = await Promise.race([request, cancelled]);
                const entries = page.entries.slice(0, 10);
                return { entries, cursor: entries.length ? (page.cursor ?? null) : null };
            } finally {
                win.clearTimeout(timeout);
                signal.removeEventListener('abort', abort);
            }
        }

        async function showPage(batch, provider, previous) {
            let page;
            try {
                page = await searchPage(batch, provider, previous?.cursor ?? null);
            } catch {
                page = { entries: [], cursor: null, failed: true };
            }
            if (search === batch) {
                const group = {
                    ...page, provider, providerId: provider.id,
                    entries: [...(previous?.entries ?? []), ...page.entries],
                };
                // Keep each group's position as other providers finish.
                batch.groups = core.mergeGroups(batch.groups, group);
                render(true);
            }
        }

        async function fanOut(batch) {
            const providers = await listProviders(batch.prefix);
            if (search !== batch) {
                return;
            }
            await Promise.allSettled(providers
                .filter(provider => batch.providerId ? provider.id === batch.providerId
                    : !batch.prefix || provider.prefix === batch.prefix)
                .map(provider => showPage(batch, provider)));
        }

        async function loadMore(batch, group) {
            if (search !== batch || group.loading) {
                return;
            }
            group.loading = true;
            await showPage(batch, group.provider, group);
        }

        async function loadCommands() {
            const url = endpoint(config.commandsUrl);
            const request = () => win.fetch(url.href, jsonRequest);
            try {
                let response = await request();
                if (response.status === 403 && (url.searchParams.has('project_id') || url.searchParams.has('task_id'))) {
                    url.searchParams.delete('project_id');
                    url.searchParams.delete('task_id');
                    response = await request();
                }
                if (response.ok) {
                    const data = await response.json();
                    commands = data.commands;
                    const available = new Set(commands.map(command => command.id));
                    recent = data.recent.filter(id => available.has(id));
                }
            } catch {
                // The dialog can still display its empty state and help.
            }
            if (dialog.open) {
                render(true);
            }
        }

        function clearFlow() {
            stopSearch();
            steps = confirmation = result = pendingRun = null;
            query.setAttribute('placeholder', placeholder);
            query.removeAttribute('readonly');
            detail.replaceChildren();
            if (errorStatus.textContent) {
                errorStatus.textContent = status.textContent = '';
            }
        }

        function restoreFocus() {
            clearFlow();
            win.clearTimeout(announcementTimer);
            resultCount = undefined;
            const previous = opener;
            opener = null;
            previous?.focus();
        }

        function closeDialog() {
            dialog.close();
            restoreFocus();
        }

        function buildDialog() {
            const label = element('h2', { id: 'switchboard-label' }, 'Switchboard');
            listbox = element('ul', { id: 'switchboard-options', role: 'listbox', 'aria-labelledby': label.id });
            query = element('input', {
                type: 'text',
                role: 'combobox',
                placeholder,
                'aria-labelledby': label.id,
                'aria-autocomplete': 'list',
                'aria-expanded': 'true',
                'aria-controls': listbox.id,
                'aria-owns': listbox.id,
                autocomplete: 'off',
            });
            empty = element('p', {});
            dialog = element('dialog', { 'data-slot': 'switchboard', 'aria-labelledby': label.id });
            detail = element('div', {});
            errorStatus = element('p', { role: 'status' });
            dialog.append(label, query, detail, listbox, empty, errorStatus);
            root.append(dialog);
            query.addEventListener('input', updateQuery);
            dialog.addEventListener('close', () => {
                if (!dialog.open && opener) {
                    restoreFocus();
                }
            });
        }

        function openPalette(mode, source) {
            if (!dialog) {
                buildDialog();
            }
            if (!dialog.open) {
                opener = source;
                dialog.showModal();
            }
            clearFlow();
            query.value = mode === 'commands' ? '>' : '';
            query.focus();
            if (commandRequest || query.value) {
                updateQuery();
            }
            commandRequest ??= loadCommands();
        }

        function recordUse(id) {
            const data = new win.FormData();
            data.append('id', id);
            data.append('csrf_token', config.csrf);
            win.navigator.sendBeacon?.(config.usedUrl, data);
        }

        function navigate(url, event) {
            if (event?.ctrlKey || event?.metaKey) {
                win.open(url, '_blank', 'noopener');
            } else {
                win.location.assign(url);
            }
        }

        function activate(command, event, term) {
            const fields = Object.entries(command.input?.properties ?? {});
            const fill = core.quickFill(command, term);
            if (fields.length && !(Object.keys(fill.input).length && fill.complete)) {
                steps = { command, fields, index: 0, values: new Map(), originalQuery: query.value };
                for (const [name, value] of Object.entries(fill.input)) {
                    steps.values.set(name, { value, label: value });
                }
                displayStep();
            } else {
                execute(command, fill.input, event);
            }
        }

        function displayStep() {
            const [name, property] = steps.fields[steps.index];
            query.setAttribute('placeholder', property.title || name);
            query.value = steps.values.get(name)?.label ?? '';
            detail.replaceChildren(element('p', {}, 'step ' + (steps.index + 1) + '/' + steps.fields.length));
            win.clearTimeout(announcementTimer);
            updateQuery();
        }

        function advanceStep(value, label = value) {
            const [name] = steps.fields[steps.index];
            if (value === undefined) {
                steps.values.delete(name);
            } else {
                steps.values.set(name, { value, label });
            }
            if (steps.index + 1 < steps.fields.length) {
                steps.index++;
                displayStep();
                return;
            }
            const { command, values, originalQuery } = steps;
            clearFlow();
            query.value = originalQuery;
            render();
            execute(command, Object.fromEntries([...values].map(([field, entry]) => [field, entry.value])));
        }

        function backStep() {
            if (steps.index > 0) {
                steps.index--;
                displayStep();
            } else {
                const { originalQuery } = steps;
                clearFlow();
                query.value = originalQuery;
                updateQuery();
            }
        }

        function submitPost(url, input) {
            const form = element('form', { method: 'post', action: url, hidden: '' });
            for (const [name, value] of Object.entries({ ...input, csrf_token: config.formCsrf })) {
                form.append(element('input', { type: 'hidden', name, value }));
            }
            doc.body.append(form);
            // Fields named "submit" must not shadow the form's native method.
            const submit = win.HTMLFormElement?.prototype.submit ?? form.submit;
            submit.call(form);
        }

        function execute(command, input, event) {
            switch (command.target.kind) {
            case 'route':
                if (canOpen(command.url)) {
                    recordUse(command.id);
                    navigate(command.url, event);
                }
                break;
            case 'modal':
                if (canOpen(command.url, true)) {
                    recordUse(command.id);
                    closeDialog();
                    win.KB.modal.open(command.url, command.target.size);
                }
                break;
            case 'key':
                recordUse(command.id);
                closeDialog();
                for (const key of command.target.key.split('+')) {
                    doc.dispatchEvent(new win.KeyboardEvent('keydown', { key, bubbles: true, cancelable: true }));
                }
                break;
            case 'post':
                if (canOpen(command.url, true)) {
                    recordUse(command.id);
                    submitPost(command.url, input);
                }
                break;
            case 'handler':
                runHandler(command.id, input);
                break;
            }
        }

        function showToast(message, undo, source) {
            // The layout renders this live region empty; one created with its message is not announced.
            if (!toastSlot) {
                toastSlot = root.querySelector('[data-slot=switchboard-toast]');
            }
            if (!toastSlot) {
                toastSlot = element('div', { role: 'status', 'data-slot': 'switchboard-toast' });
                root.append(toastSlot);
            }
            const toast = [element('span', {}, message)];
            if (undo && typeof undo.id === 'string') {
                const button = element('button', { type: 'button' }, 'Undo');
                button.addEventListener('click', () => {
                    toastSlot.replaceChildren();
                    win.clearTimeout(toastTimer);
                    openPalette('commands', source);
                    runHandler(undo.id, undo.input ?? {});
                });
                toast.push(button);
            }
            toastSlot.replaceChildren(...toast);
            win.clearTimeout(toastTimer);
            toastTimer = win.setTimeout(() => toastSlot.replaceChildren(), 5000);
        }

        function showResult(data) {
            switch (data.type) {
            case 'navigate':
                if (canOpen(data.url)) {
                    navigate(data.url);
                }
                break;
            case 'toast': {
                const source = opener;
                closeDialog();
                showToast(data.message, data.undo, source);
                break;
            }
            case 'modal':
                if (canOpen(data.url, true)) {
                    closeDialog();
                    win.KB.modal.open(data.url, data.size);
                }
                break;
            case 'list':
            case 'text':
                result = data;
                query.setAttribute('readonly', '');
                if (data.type === 'text') {
                    detail.replaceChildren(element('p', {}, data.body));
                }
                render();
                break;
            default:
                showError('command failed');
            }
        }

        async function runHandler(id, input, token) {
            if (!config.runUrl || pendingRun) {
                return;
            }
            const run = {};
            pendingRun = run;
            stopSearch();
            win.clearTimeout(announcementTimer);
            errorStatus.textContent = status.textContent = '';
            const body = new win.FormData();
            body.append('id', id);
            body.append('input', JSON.stringify(input));
            body.append('csrf_token', config.csrf);
            if (token !== undefined) {
                body.append('confirm_token', token);
            }
            try {
                const response = await win.fetch(endpoint(config.runUrl).href, { ...jsonRequest, method: 'POST', body });
                const data = await response.json().catch(() => ({}));
                if (pendingRun !== run) {
                    return;
                }
                confirmation = null;
                detail.replaceChildren();
                query.removeAttribute('readonly');
                if (response.status === 428 && canOpen(data.url)) {
                    navigate(data.url);
                } else if (!response.ok || data.error) {
                    if (token !== undefined) {
                        render();
                    }
                    showError(data.error ?? (response.status === 403 ? 'forbidden' : 'command failed'));
                } else if (data.confirm) {
                    confirmation = { id, input, token: data.confirm.token };
                    query.setAttribute('readonly', '');
                    detail.replaceChildren(element('p', {}, data.confirm.message),
                        element('p', {}, 'Press Enter again to confirm'));
                    render();
                } else {
                    showResult(data);
                }
            } catch {
                if (pendingRun === run) {
                    showError('command failed');
                }
            } finally {
                if (pendingRun === run) {
                    pendingRun = null;
                }
            }
        }

        function enter(event) {
            if (pendingRun || event.repeat) {
                return;
            }
            if (confirmation) {
                runHandler(confirmation.id, confirmation.input, confirmation.token);
            } else if (steps && !rows[selection]?.action) {
                const [name, property] = steps.fields[steps.index];
                if (!property.enum && !property.format) {
                    const optional = !steps.command.input.required?.includes(name);
                    advanceStep(optional && query.value === '' ? undefined : query.value);
                }
            } else {
                rows[selection]?.action?.(event);
            }
        }

        function onKeydown(event) {
            if (event.isComposing) {
                return;
            }
            const mode = core.shouldOpen(event);
            if (mode) {
                event.preventDefault();
                event.stopPropagation();
                openPalette(mode, doc.activeElement);
                return;
            }
            if (dialog?.open) {
                switch (event.key) {
                case 'Escape': closeDialog(); break;
                case 'ArrowDown': select(selection + 1, true); break;
                case 'ArrowUp': select(Math.max(0, selection - 1), true); break;
                case 'Home': select(0, true); break;
                case 'End': select(rows.length - 1, true); break;
                case 'Enter': enter(event); break;
                case 'Backspace':
                    if (!steps || query.value !== '') {
                        return;
                    }
                    backStep();
                    break;
                default: return;
                }
                event.preventDefault();
                event.stopPropagation();
                return;
            }
            if (core.shouldFocusSearch(event, config.singleKeys !== '0')) {
                const search = doc.querySelector('[data-slot=search]') ?? doc.getElementById('form-search');
                if (search) {
                    event.preventDefault();
                    search.focus();
                }
            }
        }

        const menus = doc.querySelector('header .menus-container');
        if (menus) {
            const trigger = element('a', {
                'data-slot': 'switchboard-trigger',
                href: config.pageUrl,
                'aria-label': 'Switchboard (Ctrl+K)',
            }, 'Switchboard');
            const menuItems = [...menus.children];
            const userMenu = menuItems.find(child => child.matches('[data-slot=user-menu]'))
                ?? menuItems.reverse().find(child => child.querySelector('.dropdown-menu-link-icon'));
            menus.insertBefore(trigger, userMenu ?? null);
            trigger.addEventListener('click', event => {
                if (!event.ctrlKey && !event.metaKey && !event.shiftKey && !event.altKey) {
                    event.preventDefault();
                    openPalette('palette', trigger);
                }
            });
        }
        doc.addEventListener('keydown', onKeydown, true);
    }

    window.SwitchboardUI = { mount };
    document.addEventListener('DOMContentLoaded', () => mount(document, window));
})();
