<?php

namespace Kanboard\Plugin\Switchboard\Model;

use Kanboard\Core\Base;
use Pimple\Container;
use Throwable;

final class Providers extends Base
{
    private Permission $permission;

    public function __construct(Container $c)
    {
        parent::__construct($c);
        $this->permission = new Permission($c);
    }

    public function listFor(array $user, array $ctx, ?string $prefix): array
    {
        $rows = [];
        foreach ($this->all($ctx) as $descriptor) {
            $order = $this->permittedOrder($descriptor, $user, $ctx, $prefix);
            if ($order !== null) {
                $rows[] = [
                    'id' => $descriptor['id'],
                    'title' => $descriptor['title'],
                    'prefix' => $descriptor['prefix'],
                    'order' => $order,
                ];
            }
        }

        usort($rows, static fn(array $a, array $b): int => $a['order'] <=> $b['order']);

        return array_map(static fn(array $row): array => array_diff_key($row, ['order' => true]), $rows);
    }

    /**
     * Pass the request's explicit search prefix in $ctx['prefix'] when present.
     */
    public function search(string $id, array $user, array $ctx, string $term, ?string $cursor, int $limit): array
    {
        $descriptor = $this->all($ctx)[$id] ?? null;
        $prefix = is_string($ctx['prefix'] ?? null) ? $ctx['prefix'] : null;
        if ($descriptor === null || $this->permittedOrder($descriptor, $user, $ctx, $prefix) === null) {
            throw new UnknownProvider('unknown provider');
        }
        if ($limit <= 0) {
            return ['entries' => [], 'cursor' => null];
        }

        try {
            $result = $descriptor['search']($user, $term, $cursor, $limit);
        } catch (Throwable $exception) {
            $this->logger->warning(sprintf('Switchboard: provider "%s" failed (%s)', $id, get_class($exception)));
            throw new ProviderFailed('provider failed');
        }

        $entries = [];
        $result = is_array($result) ? $result : [];
        $items = $result['entries'] ?? [];
        foreach (is_array($items) ? $items : [] as $entry) {
            if (!is_array($entry) || !$this->nonEmptyString($entry['title'] ?? null)
                || !$this->nonEmptyString($entry['url'] ?? null)) {
                continue;
            }
            $entries[] = $descriptor['sensitive']
                ? ['title' => $entry['title'], 'url' => $entry['url']]
                : [
                    'title' => $entry['title'],
                    'subline' => is_string($entry['subline'] ?? null) ? $entry['subline'] : '',
                    'url' => $entry['url'],
                    'icon' => is_string($entry['icon'] ?? null) ? $entry['icon'] : '',
                ];
            if (count($entries) === $limit) {
                break;
            }
        }

        $next = $result['cursor'] ?? null;

        return ['entries' => $entries, 'cursor' => is_string($next) ? $next : null];
    }

    private function all(array $ctx): array
    {
        $providers = [];
        foreach ($this->hook->getListeners('switchboard:search') as $listener) {
            try {
                $descriptors = $listener($ctx);
            } catch (Throwable) {
                $this->warn('', 'listener failed');
                continue;
            }
            if (!is_array($descriptors)) {
                $this->warn('', 'listener failed');
                continue;
            }

            foreach ($descriptors as $descriptor) {
                $descriptor = is_array($descriptor) ? $descriptor : [];
                $id = is_string($descriptor['id'] ?? null) ? $descriptor['id'] : '';
                $reason = match (true) {
                    !preg_match('/^[a-z0-9]+(\.[a-z0-9_-]+)+$/D', $id) => 'invalid id',
                    isset($providers[$id]) => 'duplicate id',
                    !$this->nonEmptyString($descriptor['title'] ?? null) => 'missing title',
                    !is_callable($descriptor['search'] ?? null) => 'search not callable',
                    !is_callable($descriptor['order'] ?? null) => 'listener failed',
                    default => null,
                };
                if ($reason !== null) {
                    $this->warn($id, $reason);
                    continue;
                }

                $providers[$id] = [
                    'prefix' => is_string($descriptor['prefix'] ?? null) ? $descriptor['prefix'] : '',
                    'sensitive' => (bool) ($descriptor['sensitive'] ?? false),
                    'external' => (bool) ($descriptor['external'] ?? false),
                ] + $descriptor;
            }
        }

        return $providers;
    }

    private function permittedOrder(array $descriptor, array $user, array $ctx, ?string $prefix): ?int
    {
        if ($descriptor['sensitive']
            && !($this->nonEmptyString($prefix) && $prefix === $descriptor['prefix'])
            && strcasecmp($ctx['route']['plugin'] ?? '', explode('.', $descriptor['id'], 2)[0]) !== 0) {
            return null;
        }
        if ($descriptor['external']) {
            $enabled = json_decode($this->configModel->get('switchboard_external_providers', '[]'));
            if (!is_array($enabled) || !in_array($descriptor['id'], $enabled, true)) {
                return null;
            }
        }
        if (!$this->permission->allows($descriptor['permission'] ?? null, $user, $ctx)) {
            return null;
        }

        try {
            $order = $descriptor['order']($ctx);
        } catch (Throwable) {
            $this->warn($descriptor['id'], 'listener failed');
            return null;
        }

        return is_int($order) ? $order : null;
    }

    private function nonEmptyString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    private function warn(string $id, string $reason): void
    {
        $this->logger->warning(sprintf('Switchboard: skipped provider "%s" (%s)', $id, $reason));
    }
}
