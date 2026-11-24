<?php

namespace Kanboard\Plugin\Switchboard\Model;

use InvalidArgumentException;
use Kanboard\Core\Base;
use Kanboard\Core\Security\Role;
use Pimple\Container;

final class InvalidInput extends InvalidArgumentException
{
    public function __construct(public readonly string $field)
    {
        parent::__construct($field);
    }
}

final class InputValidator extends Base
{
    public function __construct(Container $c, private readonly array $user)
    {
        parent::__construct($c);
    }

    public function validate(?array $schema, array $input): array
    {
        foreach ($schema['required'] ?? [] as $field) {
            if (!array_key_exists($field, $input)) {
                throw new InvalidInput($field);
            }
        }

        $clean = [];
        foreach ($schema['properties'] ?? [] as $field => $rules) {
            if (!array_key_exists($field, $input)) {
                continue;
            }

            $value = $input[$field];
            if (!is_string($value)
                || !mb_check_encoding($value, 'UTF-8')
                || mb_strlen($value, 'UTF-8') > ($rules['maxLength'] ?? 500)
                || (isset($rules['enum']) && !in_array($value, $rules['enum'], true))
                || (isset($rules['format']) && !$this->allowsId($rules['format'], $value))) {
                throw new InvalidInput($field);
            }

            $clean[$field] = $value;
        }

        return $clean;
    }

    private function allowsId(string $format, string $value): bool
    {
        $id = (int) $value;
        if (!ctype_digit($value) || $id <= 0 || (string) $id !== ltrim($value, '0')) {
            return false;
        }

        return match ($format) {
            'project' => $this->allowsProject($id),
            'task' => $this->allowsTask($id),
            'user' => $this->allowsUser($id),
            default => false,
        };
    }

    private function allowsProject(int $id): bool
    {
        return !empty($this->projectModel->getById($id)) && $this->canAccessProject($id);
    }

    private function allowsTask(int $id): bool
    {
        $task = $this->taskFinderModel->getById($id);

        return !empty($task) && $this->canAccessProject((int) $task['project_id']);
    }

    private function canAccessProject(int $id): bool
    {
        return $this->projectPermissionModel->isUserAllowed($id, $this->user['id']);
    }

    private function allowsUser(int $id): bool
    {
        if (empty($this->userModel->getById($id))) {
            return false;
        }
        if ($this->user['role'] === Role::APP_ADMIN) {
            return true;
        }

        return array_intersect(
            $this->projectPermissionModel->getActiveProjectIds($this->user['id']),
            $this->projectPermissionModel->getActiveProjectIds($id)
        ) !== [];
    }
}
