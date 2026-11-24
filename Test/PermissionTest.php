<?php

use Kanboard\Model\ProjectUserRoleModel;
use Kanboard\Plugin\Switchboard\Model\Permission;
use PHPUnit\Framework\TestCase;
use Pimple\Container;

final class PermissionTest extends TestCase
{
    /** @dataProvider permissions */
    public function testAllows(
        mixed $spec,
        string $appRole,
        array $ctx,
        ?string $projectRole,
        bool $expected
    ): void {
        $container = new Container();
        $model = $this->createMock(ProjectUserRoleModel::class);
        if ($projectRole === null) {
            $model->expects($this->never())->method('getUserRole');
        } else {
            $model->expects($this->once())->method('getUserRole')->with(15, 7)->willReturn($projectRole);
        }
        $container['projectUserRoleModel'] = $model;

        $permission = new Permission($container);
        $this->assertSame($expected, $permission->allows($spec, ['id' => 7, 'role' => $appRole], $ctx));
    }

    public static function permissions(): array
    {
        $project = ['project_id' => 15, 'user_id' => 7];

        return [
            'admin for admin' => ['app-admin', 'app-admin', [], null, true],
            'admin for manager' => ['app-manager', 'app-admin', [], null, true],
            'admin for user' => ['app-user', 'app-admin', [], null, true],
            'manager for admin' => ['app-admin', 'app-manager', [], null, false],
            'manager for manager' => ['app-manager', 'app-manager', [], null, true],
            'manager for user' => ['app-user', 'app-manager', [], null, true],
            'user for admin' => ['app-admin', 'app-user', [], null, false],
            'user for manager' => ['app-manager', 'app-user', [], null, false],
            'user for user' => ['app-user', 'app-user', [], null, true],
            'default permission' => [null, 'app-user', [], null, true],
            'default denies public' => [null, 'app-public', [], null, false],
            'invalid spec' => ['project-owner', 'app-admin', $project, null, false],
            'project manager passes member' => ['project-member', 'app-user', $project, 'project-manager', true],
            'project member passes viewer' => ['project-viewer', 'app-user', $project, 'project-member', true],
            'project viewer fails member' => ['project-member', 'app-user', $project, 'project-viewer', false],
            'no project membership' => ['project-viewer', 'app-manager', $project, '', false],
            'project missing' => ['project-viewer', 'app-user', ['project_id' => null], null, false],
            'admin overrides project role' => ['project-manager', 'app-admin', $project, null, true],
            'callable receives user and context' => [
                static fn(array $user, array $ctx): bool => $user['id'] === 7 && $ctx['project_id'] === 15,
                'app-user', $project, null, true,
            ],
            'truthy callable' => [static fn(): int => 1, 'app-user', [], null, false],
            'throwing callable' => [static fn() => throw new RuntimeException('private'), 'app-admin', [], null, false],
        ];
    }
}
