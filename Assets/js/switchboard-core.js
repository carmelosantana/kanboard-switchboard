(function () {
    'use strict';

    const GAP = 0.999;
    const INCOMPLETE = 0.99;
    const TRANSPOSITION = 0.1;

    function wordWeight(previous) {
        if (previous === ' ') {
            return 0.9;
        }
        return '-_/.:'.includes(previous) ? 0.8 : 0.17;
    }

    function scoreText(query, text) {
        if (query === text || query.length === 0) {
            return 1;
        }
        if (query.length > text.length) {
            return 0;
        }

        // Each row holds the best match for a query suffix at every text offset.
        let next = new Float64Array(text.length + 1).fill(INCOMPLETE);
        next[text.length] = 1;
        let afterNext;
        for (let q = query.length - 1; q >= 0; q--) {
            const row = new Float64Array(text.length + 1);
            let later = 0;
            for (let t = text.length - 1; t >= 0; t--) {
                const contiguous = query[q] === text[t] ? next[t + 1] : 0;
                const swapped = afterNext && query[q] === text[t + 1] && query[q + 1] === text[t]
                    ? TRANSPOSITION * afterNext[t + 2]
                    : 0;
                const gapped = GAP * later;
                row[t] = Math.max(contiguous, swapped, gapped);
                // Carry the best later start forward with one penalty per skipped character.
                if (t > 0) {
                    later = Math.max(contiguous * wordWeight(text[t - 1]), swapped, gapped);
                }
            }
            afterNext = next;
            next = row;
        }
        return next[0];
    }

    function score(query, text, keywords = []) {
        const normalized = query.toLowerCase();
        let best = scoreText(normalized, text.toLowerCase());
        for (const keyword of keywords) {
            best = Math.max(best, scoreText(normalized, keyword.toLowerCase()));
        }
        return best;
    }

    function rank(commands, query, frecencyIds = []) {
        const positions = new Map();
        frecencyIds.forEach((id, position) => {
            if (!positions.has(id)) {
                positions.set(id, position);
            }
        });
        const byFrecency = (a, b) =>
            (positions.get(a.id) ?? frecencyIds.length) - (positions.get(b.id) ?? frecencyIds.length);
        const byTitle = (a, b) => a.title.localeCompare(b.title);

        if (query === '') {
            return [...commands].sort((a, b) => byFrecency(a, b)
                || (a.group ?? '').localeCompare(b.group ?? '') || byTitle(a, b));
        }

        return commands
            .map(command => ({ command, score: score(query, command.title, command.keywords) }))
            .filter(match => match.score > 0)
            .sort((a, b) => b.score - a.score || byFrecency(a.command, b.command) || byTitle(a.command, b.command))
            .map(match => match.command);
    }

    const modes = { '>': 'commands', '#': 'search', '@': 'search', ':': 'search', '?': 'help' };

    function parseQuery(raw) {
        const prefix = raw.charAt(0);
        const mode = modes[prefix];
        return mode
            ? { mode, prefix, term: raw.slice(1).trimStart() }
            : { mode: 'all', prefix: '', term: raw };
    }

    function quickFill(command, term) {
        const schema = command.input;
        const field = Object.entries(schema?.properties ?? {}).find(([, property]) =>
            property.type === 'string' && !('enum' in property) && !('format' in property));
        let input = {};

        if (field) {
            let prefixLength = 0;
            for (const alias of [command.title, ...(command.keywords ?? [])]) {
                if (alias.length > prefixLength && term.charAt(alias.length) === ' '
                    && term.slice(0, alias.length).toLowerCase() === alias.toLowerCase()) {
                    prefixLength = alias.length;
                }
            }
            const value = prefixLength > 0 ? term.slice(prefixLength).trim() : '';
            if (value !== '') {
                input = { [field[0]]: value };
            }
        }

        const complete = (schema?.required ?? []).every(name => Object.prototype.hasOwnProperty.call(input, name));
        return { input, complete };
    }

    function shouldOpen(evt) {
        if (evt.altKey || !(evt.ctrlKey || evt.metaKey) || evt.key?.toLowerCase() !== 'k') {
            return null;
        }
        if (evt.shiftKey) {
            return 'commands';
        }
        const target = evt.target;
        return target?.isContentEditable || target?.tagName?.toUpperCase() === 'TEXTAREA'
            ? null
            : 'palette';
    }

    function shouldFocusSearch(evt, singleKeysEnabled) {
        const target = evt.target;
        return Boolean(singleKeysEnabled && evt.key === '/'
            && !(evt.ctrlKey || evt.metaKey || evt.altKey || evt.shiftKey)
            && !target?.isContentEditable
            && !['INPUT', 'TEXTAREA', 'SELECT'].includes(target?.tagName?.toUpperCase()));
    }

    function mergeGroups(current, incoming, order) {
        // The caller keeps groups for one query at a time. Display follows arrival order.
        const index = current.findIndex(group => group.providerId === incoming.providerId);
        return index === -1
            ? [...current, incoming]
            : current.map((group, position) => position === index ? incoming : group);
    }

    window.SwitchboardCore = { score, rank, parseQuery, quickFill, shouldOpen, shouldFocusSearch, mergeGroups };
})();
