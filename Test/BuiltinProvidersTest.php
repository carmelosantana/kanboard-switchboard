<?php

use Kanboard\Core\Http\Request;
use Kanboard\Core\Plugin\Hook;
use Kanboard\Core\Security\Role;
use Kanboard\Plugin\Switchboard\Model\Providers;
use Kanboard\Plugin\Switchboard\Plugin;
use KanboardTests\units\Base;

final class BuiltinProvidersTest extends Base
{
    private Providers $providers;
    private array $alice;
    private array $bob;
    private int $projectOne;
    private int $projectTwo;
    private int $archivedProject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->container['hook'] = new Hook();
        $this->container['request'] = new Request($this->container);
        (new Plugin($this->container))->initialize();
        $this->providers = $this->container['switchboardProviders'];

        $users = $this->container['userModel'];
        $this->alice = ['id' => $users->create(['username' => 'member-alice', 'name' => 'Alice Member']), 'role' => Role::APP_USER];
        $this->bob = ['id' => $users->create(['username' => 'member-bob', 'name' => 'Bob Member']), 'role' => Role::APP_USER];
        $projects = $this->container['projectModel'];
        $this->projectOne = $projects->create(['name' => 'Shared One']);
        $this->projectTwo = $projects->create(['name' => 'Shared Two']);
        $this->archivedProject = $projects->create(['name' => 'Shared Archived', 'is_active' => 0]);
        $roles = $this->container['projectUserRoleModel'];
        $this->assertTrue($roles->addUser($this->projectOne, $this->alice['id'], Role::PROJECT_MEMBER));
        $this->assertTrue($roles->addUser($this->projectTwo, $this->bob['id'], Role::PROJECT_MEMBER));
        $this->assertTrue($roles->addUser($this->archivedProject, $this->alice['id'], Role::PROJECT_MEMBER));
    }

    public function testPluginRegistersBuiltInProvidersInOrder(): void
    {
        $this->assertSame([
            ['id' => 'switchboard.tasks', 'title' => 'Tasks', 'prefix' => '#'],
            ['id' => 'switchboard.projects', 'title' => 'Projects', 'prefix' => ':'],
            ['id' => 'switchboard.users', 'title' => 'Users', 'prefix' => '@'],
        ], $this->providers->listFor($this->alice, [], null));
    }

    public function testTaskTitleSearchOnlyFindsOpenTasksInActiveMemberProjects(): void
    {
        $id = $this->createTask('Visible Ticket', $this->projectOne);
        $this->createTask('Hidden Ticket', $this->projectTwo);
        $this->createTask('Archived Ticket', $this->archivedProject);
        $closedId = $this->createTask('Closed Ticket', $this->projectOne);
        $this->assertTrue($this->container['taskStatusModel']->close($closedId));

        $result = $this->search('tasks', 'tIcKeT');
        $this->assertSame(['Visible Ticket'], array_column($result['entries'], 'title'));
        $this->assertSame(
            '?controller=TaskViewController&action=show&task_id='.$id.'&project_id='.$this->projectOne,
            $result['entries'][0]['url']
        );
    }

    public function testNumericTaskSearchFindsByIdWithinMemberProjects(): void
    {
        $visible = $this->createTask('Visible ticket', $this->projectOne);
        $hidden = $this->createTask('Hidden ticket', $this->projectTwo);
        $this->createTask('Task named '.$hidden, $this->projectOne);

        $this->assertSame(['Visible ticket'], array_column($this->search('tasks', (string) $visible)['entries'], 'title'));
        $this->assertSame([], $this->search('tasks', (string) $hidden)['entries']);
    }

    public function testProjectSearchUsesMembershipAndAdminSeesAllActiveProjects(): void
    {
        $this->assertSame(['Shared One'], array_column($this->search('projects', 'sHaReD')['entries'], 'title'));
        $admin = ['id' => 1, 'role' => Role::APP_ADMIN];
        $this->assertSame(['Shared One', 'Shared Two'], array_column($this->search('projects', 'shared', user: $admin)['entries'], 'title'));
    }

    public function testUserSearchMatchesNameAndUsernameInSharedActiveProjects(): void
    {
        $this->assertSame(['Alice Member'], array_column($this->search('users', 'member-')['entries'], 'title'));
        $this->assertSame(['Alice Member'], array_column($this->search('users', 'ALICE MEMBER')['entries'], 'title'));
        $this->assertSame([], $this->search('users', 'bob')['entries']);

        $admin = ['id' => 1, 'role' => Role::APP_ADMIN];
        $this->assertSame(['Alice Member', 'Bob Member'], array_column($this->search('users', 'member-', user: $admin)['entries'], 'title'));
    }

    public function testTaskPagingReturnsOffsetOnlyForAFullPage(): void
    {
        $titles = [];
        for ($index = 1; $index <= 12; $index++) {
            $titles[] = 'Paged task '.$index;
            $this->createTask($titles[$index - 1], $this->projectOne);
        }

        $first = $this->search('tasks', 'Paged', null, 10);
        $this->assertCount(10, $first['entries']);
        $this->assertSame('10', $first['cursor']);

        $second = $this->search('tasks', 'Paged', $first['cursor'], 10);
        $this->assertCount(2, $second['entries']);
        $this->assertNull($second['cursor']);
        $this->assertEqualsCanonicalizing(
            $titles,
            array_column(array_merge($first['entries'], $second['entries']), 'title')
        );
    }

    private function search(string $provider, string $term, ?string $cursor = null, int $limit = 10, ?array $user = null): array
    {
        return $this->providers->search('switchboard.'.$provider, $user ?? $this->alice, [], $term, $cursor, $limit);
    }

    private function createTask(string $title, int $project): int
    {
        $id = $this->container['taskCreationModel']->create(['title' => $title, 'project_id' => $project]);
        $this->assertGreaterThan(0, $id);

        return $id;
    }
}
