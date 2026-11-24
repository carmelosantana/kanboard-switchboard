# Switchboard

A command palette for Kanboard. Press **Ctrl/⌘+K** anywhere, type, and run any action you're allowed to: jump to a project, open a task, switch views, or run a plugin's command. Plugins add their own commands and search providers through two hooks.

Requires Kanboard ≥ 1.2.47 and PHP ≥ 8.4. It works on stock Kanboard and under ShadcnTheme (light and dark), and uses SuiteUI's tokens when SuiteUI is installed.

## Install

Copy the `Switchboard` folder into `plugins/`, or install it from ModMenu. No database tables, no cron.

## Keys

| Key | Does |
| --- | --- |
| Ctrl/⌘+K | Open the palette. Works in single-line inputs. Does nothing inside a textarea or rich editor, which keep their own link key. |
| Ctrl/⌘+Shift+K | Open the palette in command mode. Works everywhere, editors included. |
| `/` | Focus the page's search box when focus isn't in a field. |
| Esc | Close the palette. Focus returns to where it was. |
| Ctrl/⌘+Enter | Open a navigation result in a new tab. |

Inside the palette, a prefix picks a mode: `>` commands, `#` tasks, `@` users, `:` projects, `?` help.

Core Kanboard shortcuts (`b`, `n`, `f`, `r`, `v o`, `v b`, `v l`, `?`) show up as commands with their keys, and keep working as before.

**Single-key shortcuts:** each user can turn off `/` under *My profile → Switchboard* (WCAG 2.1.4). The same page clears recent commands.

**Mouse and touch:** a search button in the header opens the palette. Without JavaScript, *Search and commands* in the user menu opens a plain page (`/switchboard`) with the same commands as links and forms, plus a search box.

## Adding commands

Register a listener in your plugin's `initialize()`. It receives the page context and returns plain arrays. You don't need a class from Switchboard, and the listener does nothing when Switchboard isn't installed.

```php
$this->hook->on('switchboard:commands', function (array $ctx): array {
    return [[
        'id'          => 'timeblock.timer.start',            // namespaced: <plugin>.<verb>
        'title'       => t('Start timer'),
        'description' => 'Start the time tracker on a task', // read by agents later
        'group'       => 'Time',
        'keywords'    => ['track', 'clock in'],
        'permission'  => 'project-member',                   // or app-admin|app-manager|app-user, project-manager|project-viewer, or a Closure
        'risk'        => 'reversible',                       // read | reversible | consequential
        'input'       => [
            'type'       => 'object',
            'properties' => ['task' => ['type' => 'string', 'format' => 'task', 'title' => 'Task']],
            'required'   => ['task'],
        ],
        'target'      => ['kind' => 'handler', 'handler' => function (array $user, array $ctx, array $input): array {
            // start the timer on (int) $input['task'] …
            return ['type' => 'toast', 'message' => t('Timer started'),
                    'undo' => ['id' => 'timeblock.timer.stop', 'input' => ['task' => $input['task']]]];
        }],
    ]];
});
```

`$ctx` holds `user_id`, `route` (`controller`, `action`, `plugin`), `project_id` and `task_id`. If `task_id` is set, `project_id` is always that task's project. Listeners must not query the database; use a search provider for anything that needs data.

**Targets:**

- `route`: `['kind' => 'route', 'controller' => …, 'action' => …, 'params' => […]]` navigates.
- `modal`: the same keys plus `size` opens Kanboard's modal.
- `post`: submits a real form to an existing POST action with Kanboard's form CSRF token and the input fields.
- `handler`: runs your callable on the server through `POST switchboard/run`.

**Input:** at most 3 properties. Each is a `string`, optionally with `maxLength`, an `enum`, or `format` of `project`, `task` or `user`, plus an optional `title` (≤ 80 characters) used as the prompt. The palette asks for one field per step. Text typed after the command, as in `>start timer …`, fills the first text field.

**Handler results:**

- `navigate {url}`
- `toast {message, undo?}`
- `list {items: up to 10 × {title, url, subline?}}`
- `modal {url, size}`
- `text {body, continue_url?}`

**Risk:**

- `read` and `reversible` run at once.
- `consequential` asks for a confirm step first. The confirm token binds the user, command, input and page context, and lasts 120 s.
- `step_up: true` also asks the host adapter (below).
- Only `handler` targets may be `consequential` or `step_up`. Other targets with either setting are skipped.

Every run re-checks `permission` on the server, so a hidden command can't be called directly. Bad descriptors (no namespace, duplicate id, unknown risk or target, more than 3 inputs) are skipped and logged as `Switchboard: skipped command "{id}" ({reason})`. One broken plugin never breaks the palette.

## Adding search

```php
$this->hook->on('switchboard:search', fn (array $ctx): array => [[
    'id'        => 'vault.entries',
    'title'     => t('Vault'),
    'prefix'    => '!',
    'order'     => fn (array $ctx): ?int => 40,   // null hides the provider on this page
    'permission'=> 'app-user',
    'sensitive' => true,    // titles + URLs only; searched only via its prefix or on its own plugin's pages
    'external'  => false,   // true = calls an outside service; an admin must enable it
    'search'    => function (array $user, string $term, ?string $cursor, int $limit): array {
        return ['entries' => [/* ['title' => …, 'url' => …, 'subline' => …, 'id' => …] */], 'cursor' => null];
    },
]]);
```

The browser queries each provider in its own request: debounced 250 ms, 3 s timeout, 10 results per page with *Load more*. A slow or failing provider shows *Search unavailable* in its group and never blocks the rest. Built in: tasks (`#`), projects (`:`) and users (`@`), always limited to the caller's active projects.

## Host adapter (KanbOS step-up)

A host plugin can register one listener as a plain array:

```php
$this->hook->on('switchboard:host', [
    'name'    => 'floorboard',
    'step_up' => fn (array $user): array => ['ok' => false, 'url' => '/joist/step-up'],
]);
```

`ok: true` means the user is inside a step-up window. `ok: false` returns HTTP 428 with the URL, and the palette sends the user there.

## Endpoints

These are all under the plugin's routes. Every one needs a logged-in user, and the POSTs need the reusable CSRF token.

| Route | Does |
| --- | --- |
| `GET switchboard/commands` | Permitted commands for the page context, plus the user's recent ids |
| `GET switchboard/providers?prefix=` | Ordered search providers |
| `GET switchboard/search?provider&term&cursor&limit&prefix` | One provider's results |
| `POST switchboard/run` | Run a command (`id`, `input` JSON, `confirm_token`) |
| `POST switchboard/used` | Record a navigation for recents |
| `GET switchboard` | The no-JS page |

## Tests

```bash
./testing/run-plugin-tests.sh Switchboard           # from the kanboard-plugins harness root
node --test Switchboard/Test/js/*.test.mjs
```

## License

MIT, Carmelo Santana.
