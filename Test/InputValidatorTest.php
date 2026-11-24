<?php

use Kanboard\Core\Security\Role;
use Kanboard\Plugin\Switchboard\Model\InputValidator;
use Kanboard\Plugin\Switchboard\Model\InvalidInput;
use KanboardTests\units\Base;

final class InputValidatorTest extends Base
{
    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testInvalidInputCanBeAutoloadedIndependently(): void
    {
        $error = new InvalidInput('value');
        $this->assertSame('value', $error->field);
    }

    public function testOnlyDeclaredFieldsAreKeptAndOptionalFieldsMayBeOmitted(): void
    {
        $validator = new InputValidator($this->container, ['id' => 1, 'role' => Role::APP_USER]);
        $schema = ['type' => 'object', 'properties' => [
            'name' => ['type' => 'string', 'maxLength' => 2],
            'optional' => ['type' => 'string'],
        ]];

        $this->assertSame(['name' => 'é字'], $validator->validate($schema, ['name' => 'é字', 'extra' => 'drop']));
        $this->assertSame([], $validator->validate(null, ['extra' => 'drop']));
    }

    /** @dataProvider invalidValues */
    public function testInvalidValuesIdentifyTheField(array $rules, array $input): void
    {
        $validator = new InputValidator($this->container, ['id' => 1, 'role' => Role::APP_USER]);
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('value');
        $validator->validate(self::schema($rules), $input);
    }

    public static function invalidValues(): array
    {
        return [
            'required' => [[], []],
            'null' => [[], ['value' => null]],
            'number' => [[], ['value' => 1]],
            'default length' => [[], ['value' => str_repeat('x', 501)]],
            'custom length' => [['maxLength' => 2], ['value' => 'abc']],
            'enum' => [['enum' => ['yes', 'no']], ['value' => 'YES']],
            'numeric exponent' => [['format' => 'project'], ['value' => '1e0']],
            'zero' => [['format' => 'task'], ['value' => '0']],
            'overflow' => [['format' => 'user'], ['value' => str_repeat('9', 22)]],
        ];
    }

    public function testProjectTaskAndUserFormatsRequireAccessibleExistingRecords(): void
    {
        $users = $this->container['userModel'];
        $alice = $users->create(['username' => 'alice']);
        $bob = $users->create(['username' => 'bob']);
        $projects = $this->container['projectModel'];
        $shared = $projects->create(['name' => 'Shared']);
        $private = $projects->create(['name' => 'Private', 'is_private' => 1]);
        $archived = $projects->create(['name' => 'Archived', 'is_active' => 0]);
        $roles = $this->container['projectUserRoleModel'];
        $roles->addUser($shared, $alice, Role::PROJECT_MEMBER);
        $roles->addUser($private, $bob, Role::PROJECT_MANAGER);
        $roles->addUser($archived, $alice, Role::PROJECT_MEMBER);
        $roles->addUser($archived, $bob, Role::PROJECT_MEMBER);

        $tasks = $this->container['taskCreationModel'];
        $visibleTask = $tasks->create(['title' => 'Visible', 'project_id' => $shared]);
        $hiddenTask = $tasks->create(['title' => 'Hidden', 'project_id' => $private]);
        $validator = new InputValidator($this->container, ['id' => $alice, 'role' => Role::APP_USER]);

        $this->assertAccepted($validator, 'project', $shared);
        $this->assertAccepted($validator, 'task', $visibleTask);
        $this->assertRejected($validator, 'project', $private);
        $this->assertRejected($validator, 'task', $hiddenTask);
        $this->assertRejected($validator, 'user', $bob);
        foreach (['project', 'task', 'user'] as $format) {
            $this->assertRejected($validator, $format, 9999);
        }

        $roles->addUser($shared, $bob, Role::PROJECT_MEMBER);
        $this->assertAccepted($validator, 'user', $bob);

        $admin = new InputValidator($this->container, ['id' => 1, 'role' => Role::APP_ADMIN]);
        $this->assertAccepted($admin, 'user', $bob);
        $this->assertRejected($admin, 'user', 9999);
    }

    private static function schema(array $rules): array
    {
        return [
            'type' => 'object',
            'properties' => ['value' => ['type' => 'string'] + $rules],
            'required' => ['value'],
        ];
    }

    private function assertAccepted(InputValidator $validator, string $format, int $id): void
    {
        $input = ['value' => (string) $id];
        $this->assertSame($input, $validator->validate(self::schema(['format' => $format]), $input));
    }

    private function assertRejected(InputValidator $validator, string $format, int $id): void
    {
        try {
            $validator->validate(self::schema(['format' => $format]), ['value' => (string) $id]);
            $this->fail('Expected invalid '.$format.' id');
        } catch (InvalidInput $error) {
            $this->assertSame('value', $error->getMessage());
        }
    }
}
