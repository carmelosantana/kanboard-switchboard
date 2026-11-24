(function () {
    'use strict';

    const mounted = new WeakSet();
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
        let dialog, query, listbox, empty, opener;
        let commands = [], recent = [], commandRequest;
        let rows = [], selection = -1;
        let announcementTimer, resultCount;

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

        function render() {
            const { mode, term } = core.parseQuery(query.value);
            const results = mode === 'help' ? help.map(title => ({ title }))
                : mode === 'search' ? [] : core.rank(commands, term, recent);
            let group;
            listbox.replaceChildren();
            rows = results.map((command, index) => {
                if (command.group && command.group !== group) {
                    group = command.group;
                    listbox.append(element('li', { role: 'presentation' }, group));
                }
                const label = [command.title, command.group,
                    command.target?.kind === 'route' ? 'opens page' : ''].filter(Boolean).join(', ');
                const option = element('li', {
                    id: 'switchboard-option-' + index,
                    role: 'option',
                    'aria-label': label,
                    'aria-selected': 'false',
                }, command.title);
                if (mode === 'help') {
                    option.setAttribute('aria-disabled', 'true');
                } else {
                    option.addEventListener('click', event => activate(command, event));
                }
                listbox.append(option);
                return { command, option };
            });
            selection = -1;
            select(0);
            empty.textContent = results.length ? '' : mode === 'commands' ? 'No matching commands' : 'No results';
            announce(results.length);
        }

        async function loadCommands() {
            const url = new win.URL(config.commandsUrl, win.location.href);
            const params = {
                from_controller: config.fromController,
                from_action: config.fromAction,
                from_plugin: config.fromPlugin,
                project_id: config.projectId,
                task_id: config.taskId,
            };
            for (const [name, value] of Object.entries(params)) {
                if (value) {
                    url.searchParams.set(name, value);
                }
            }
            const request = () => win.fetch(url.href, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
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
                render();
            }
        }

        function restoreFocus() {
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
                placeholder: 'Type a command or search…',
                'aria-labelledby': label.id,
                'aria-autocomplete': 'list',
                'aria-expanded': 'true',
                'aria-controls': listbox.id,
                'aria-owns': listbox.id,
                autocomplete: 'off',
            });
            empty = element('p', {});
            dialog = element('dialog', { 'data-slot': 'switchboard', 'aria-labelledby': label.id });
            dialog.append(label, query, listbox, empty);
            root.append(dialog);
            query.addEventListener('input', render);
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
            query.value = mode === 'commands' ? '>' : '';
            query.focus();
            if (commandRequest || query.value) {
                render();
            }
            commandRequest ??= loadCommands();
        }

        function recordUse(id) {
            const data = new win.FormData();
            data.append('id', id);
            data.append('csrf_token', config.csrf);
            win.navigator.sendBeacon?.(config.usedUrl, data);
        }

        function activate(command, event) {
            switch (command?.target?.kind) {
            case 'route':
                recordUse(command.id);
                if (event.ctrlKey || event.metaKey) {
                    win.open(command.url, '_blank', 'noopener');
                } else {
                    win.location.assign(command.url);
                }
                break;
            case 'modal':
                recordUse(command.id);
                closeDialog();
                win.KB.modal.open(command.url, command.target.size);
                break;
            case 'key':
                recordUse(command.id);
                closeDialog();
                for (const key of command.target.key.split('+')) {
                    doc.dispatchEvent(new win.KeyboardEvent('keydown', { key, bubbles: true, cancelable: true }));
                }
                break;
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
                case 'Enter': activate(rows[selection]?.command, event); break;
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
