# Changelog

## 1.0.0 (Unreleased)

- Command palette on Ctrl/⌘+K (and Ctrl/⌘+Shift+K in editors), with `/` to focus page search and a header button.
- `switchboard:commands` registry: namespaced descriptors with permissions, risk tiers, typed input (up to 3 fields) and route, modal, post or handler targets.
- `switchboard:search` providers, queried in parallel from the browser. Built-in task, project and user search, and sensitive providers kept out of global search.
- A single execute endpoint that re-checks permission, validates input, confirms consequential commands with context-bound tokens, and asks the `switchboard:host` adapter for step-up.
- Per-user recents (frecency) in a small `switchboard_recent` table (SQLite, MariaDB, Postgres), and core shortcuts listed with their keys.
- No-JS page at `/switchboard` with the same commands, forms, confirm step and search.
- Per-user setting to turn off single-key shortcuts; admin setting for external providers.
- Styles on the shadcn tokens with fallbacks for stock Kanboard; reduced-motion aware.
