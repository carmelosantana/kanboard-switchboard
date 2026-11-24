# Changelog

## 1.0.1 — unreleased

- A command that ran is no longer reported as failed when recording it in recents fails; the error is logged.
- `%`, `_` and `!` in task, project and user search terms match literally.
- Command and provider ids follow one rule (`CommandId`), capped at 191 characters, the recents key length.
- Core command titles are translated.
- Toasts fill one `role="status"` region the layout renders empty, so screen readers announce them.
- Command and search rows share one accessible-name order: title, group, then hints.

## 1.0.0 — 2026-11-24

- Command palette on Ctrl/⌘+K (and Ctrl/⌘+Shift+K in editors), with `/` to focus page search and a header button.
- `switchboard:commands` registry: namespaced descriptors with permissions, risk tiers, typed input (up to 3 fields) and route, modal, post or handler targets.
- `switchboard:search` providers, queried in parallel from the browser. Built-in task, project and user search, and sensitive providers kept out of global search.
- A single execute endpoint that re-checks permission, validates input, confirms consequential commands with context-bound tokens, and asks the `switchboard:host` adapter for step-up.
- Per-user recents (frecency) in a small `switchboard_recent` table (SQLite, MariaDB, Postgres), and core shortcuts listed with their keys.
- No-JS page at `/switchboard` with the same commands, forms, confirm step and search.
- Per-user setting to turn off single-key shortcuts; admin setting for external providers.
- Styles on the shadcn tokens with fallbacks for stock Kanboard; reduced-motion aware.
