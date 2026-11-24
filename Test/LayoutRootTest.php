<?php

require_once 'tests/units/Base.php';

use Kanboard\Core\Http\Request;
use Kanboard\Core\Plugin\Hook;
use Kanboard\Core\Security\Role;
use Kanboard\Plugin\Switchboard\Plugin;
use KanboardTests\units\Base;

final class LayoutRootTest extends Base
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->container['hook'] = new Hook();
        $this->container['request'] = new Request(
            $this->container,
            ['REQUEST_METHOD' => 'GET'],
            ['project_id' => '21', 'task_id' => '42']
        );
        (new Plugin($this->container))->initialize();
    }

    public function testGuestsHaveNoLayoutRoot(): void
    {
        $this->assertSame('', trim($this->renderLayoutBottom()));
    }

    public function testLoggedInRootHasEndpointsContextAndStatus(): void
    {
        $this->logIn();
        $root = $this->layoutRoot();

        foreach (['commands', 'providers', 'search', 'run', 'used', 'page'] as $endpoint) {
            $this->assertNotEmpty($root->getAttribute("data-$endpoint-url"));
        }
        $this->assertNotEmpty($root->getAttribute('data-csrf'));
        $this->assertTrue($this->container['token']->validateCSRFToken($root->getAttribute('data-form-csrf')));
        $this->assertSame('1', $root->getAttribute('data-single-keys'));
        $this->assertSame('21', $root->getAttribute('data-project-id'));
        $this->assertSame('42', $root->getAttribute('data-task-id'));
        $this->assertNotNull($root->querySelector('[data-slot="switchboard-status"][role="status"]'));
    }

    public function testSavedPreferenceDisablesSingleKeyShortcuts(): void
    {
        $userId = $this->logIn();
        $this->assertTrue($this->container['userMetadataModel']->save($userId, ['switchboard.single_keys' => '0']));

        $this->assertSame('0', $this->layoutRoot()->getAttribute('data-single-keys'));
    }

    private function logIn(): int
    {
        $users = $this->container['userModel'];
        $userId = $users->create(['username' => 'layout-user', 'role' => Role::APP_USER]);
        $this->container['userSession']->initialize($users->getById($userId));

        return $userId;
    }

    private function renderLayoutBottom(): string
    {
        return $this->container['helper']->hook->render('template:layout:bottom');
    }

    private function layoutRoot(): \Dom\Element
    {
        $document = \Dom\HTMLDocument::createFromString('<!DOCTYPE html>'.$this->renderLayoutBottom());
        $root = $document->getElementById('switchboard-root');
        $this->assertInstanceOf(\Dom\Element::class, $root);

        return $root;
    }
}
