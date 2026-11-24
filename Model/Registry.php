<?php

namespace Kanboard\Plugin\Switchboard\Model;

use Closure;
use Kanboard\Core\Base;
use Kanboard\Plugin\AiConnector\Model\ProviderRegistry;
use Pimple\Container;
use Throwable;

class Registry extends Base
{
    private const DEFAULTS = [
        'group' => 'General',
        'keywords' => [],
        'icon' => 'command',
        'risk' => 'read',
        'step_up' => false,
        'requires_ai' => false,
        'input' => null,
    ];

    private Permission $permission;

    public function __construct(Container $c)
    {
        parent::__construct($c);
        $this->permission = new Permission($c);
    }

    public function all(array $ctx): array
    {
        $commands = [];
        foreach ($this->hook->getListeners('switchboard:commands') as $listener) {
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
                $descriptor = (is_array($descriptor) ? $descriptor : []) + self::DEFAULTS;
                $id = is_string($descriptor['id'] ?? null) ? $descriptor['id'] : '';
                $reason = $this->invalidReason($descriptor, $id, isset($commands[$id]));
                if ($reason !== null) {
                    $this->warn($id, $reason);
                    continue;
                }

                $commands[$id] = $this->normalize($descriptor);
            }
        }

        usort($commands, static fn(array $a, array $b): int =>
            strcasecmp($a['group'], $b['group']) ?: strcasecmp($a['title'], $b['title']));

        return $commands;
    }

    public function permitted(array $user, array $ctx): array
    {
        return array_values(array_filter(
            $this->all($ctx),
            fn(array $descriptor): bool => $this->isPermitted($descriptor, $user, $ctx)
        ));
    }

    public function find(string $id, array $user, array $ctx): ?array
    {
        foreach ($this->all($ctx) as $descriptor) {
            if ($descriptor['id'] === $id && $this->isPermitted($descriptor, $user, $ctx)) {
                return $descriptor;
            }
        }

        return null;
    }

    public function toClient(array $descriptor): array
    {
        $client = array_intersect_key($descriptor, self::DEFAULTS + [
            'id' => true,
            'title' => true,
            'description' => true,
            'shortcut' => true,
        ]);
        $target = $descriptor['target'];
        $kind = $target['kind'];
        $client['target'] = ['kind' => $kind];

        if (in_array($kind, ['route', 'modal', 'post'], true)) {
            $client['url'] = $this->helper->url->to($target['controller'], $target['action'], $target['params'] ?? []);
        }
        if ($kind === 'modal' && isset($target['size'])) {
            $client['target']['size'] = $target['size'];
        }
        if ($kind === 'key') {
            $client['target']['key'] = $target['key'];
        }

        return $client;
    }

    private function isPermitted(array $descriptor, array $user, array $ctx): bool
    {
        return $this->permission->allows($descriptor['permission'] ?? null, $user, $ctx)
            && (!$descriptor['requires_ai'] || $this->aiReady());
    }

    private function aiReady(): bool
    {
        try {
            return class_exists(ProviderRegistry::class) && (new ProviderRegistry($this->container))->isReady();
        } catch (Throwable) {
            return false;
        }
    }

    private function invalidReason(array $descriptor, string $id, bool $duplicate): ?string
    {
        return match (true) {
            !preg_match('/^[a-z0-9]+(\.[a-z0-9_-]+)+$/D', $id) => 'invalid id',
            $duplicate => 'duplicate id',
            !$this->nonEmptyString($descriptor['title'] ?? null) => 'missing title',
            !in_array($descriptor['risk'], ['read', 'reversible', 'consequential'], true) => 'invalid risk',
            !$this->validTarget($descriptor['target'] ?? null, $id) => 'invalid target',
            $descriptor['target']['kind'] !== 'handler'
                && ($descriptor['risk'] === 'consequential' || $descriptor['step_up']) => 'invalid risk',
            !$this->validInput($descriptor['input']) => 'invalid input schema',
            default => null,
        };
    }

    private function validTarget(mixed $target, string $id): bool
    {
        if (!is_array($target)) {
            return false;
        }

        $handler = $target['handler'] ?? null;
        return match ($target['kind'] ?? null) {
            'key' => str_starts_with($id, 'switchboard.core.') && $this->nonEmptyString($target['key'] ?? null),
            'handler' => ($handler instanceof Closure || is_array($handler)) && is_callable($handler),
            'route', 'modal', 'post' => $this->nonEmptyString($target['controller'] ?? null)
                && $this->nonEmptyString($target['action'] ?? null)
                && is_array($target['params'] ?? [])
                && (!isset($target['size']) || $this->nonEmptyString($target['size'])),
            default => false,
        };
    }

    private function validInput(mixed $input): bool
    {
        if ($input === null) {
            return true;
        }

        if (!is_array($input) || ($input['type'] ?? null) !== 'object'
            || !is_array($input['properties'] ?? null) || count($input['properties']) > 3
            || array_diff_key($input, ['type' => true, 'properties' => true, 'required' => true])) {
            return false;
        }

        foreach ($input['properties'] as $name => $property) {
            if (!$this->nonEmptyString($name) || !$this->validProperty($property)) {
                return false;
            }
        }

        $required = $input['required'] ?? [];
        return is_array($required) && array_is_list($required)
            && array_all($required, static fn($name): bool => is_string($name) && isset($input['properties'][$name]));
    }

    private function validProperty(mixed $property): bool
    {
        if (!is_array($property) || ($property['type'] ?? null) !== 'string'
            || array_diff_key($property, [
                'type' => true, 'title' => true, 'maxLength' => true, 'enum' => true, 'format' => true,
            ])) {
            return false;
        }

        $title = $property['title'] ?? null;
        $maxLength = $property['maxLength'] ?? 500;
        $enum = $property['enum'] ?? null;
        $format = $property['format'] ?? null;

        return (!array_key_exists('title', $property) || ($this->nonEmptyString($title) && mb_strlen($title, 'UTF-8') <= 80))
            && (!array_key_exists('maxLength', $property) || (is_int($property['maxLength']) && $maxLength >= 0))
            && (!array_key_exists('enum', $property)
                || (is_array($enum) && array_is_list($enum) && $enum !== []
                    && array_all($enum, static fn($value): bool => is_string($value))))
            && (!array_key_exists('format', $property) || in_array($format, ['project', 'task', 'user'], true));
    }

    private function normalize(array $descriptor): array
    {
        foreach (['group', 'icon'] as $field) {
            if (!$this->nonEmptyString($descriptor[$field])) {
                $descriptor[$field] = self::DEFAULTS[$field];
            }
        }
        foreach (['description', 'shortcut'] as $field) {
            if (!is_string($descriptor[$field] ?? null)) {
                unset($descriptor[$field]);
            }
        }
        $descriptor['keywords'] = is_array($descriptor['keywords'])
            ? array_values(array_filter($descriptor['keywords'], is_string(...)))
            : [];
        $descriptor['step_up'] = (bool) $descriptor['step_up'];
        $descriptor['requires_ai'] = (bool) $descriptor['requires_ai'];

        return $descriptor;
    }

    private function nonEmptyString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    private function warn(string $id, string $reason): void
    {
        $this->logger->warning(sprintf('Switchboard: skipped command "%s" (%s)', $id, $reason));
    }
}
