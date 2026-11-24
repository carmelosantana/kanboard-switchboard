<?php

require_once 'tests/units/Base.php';

use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Core\Http\Request;
use Kanboard\Core\Http\Response;
use Kanboard\Core\Plugin\Hook;
use Kanboard\Core\Security\Role;
use Kanboard\Plugin\Switchboard\Controller\PageController;
use Kanboard\Plugin\Switchboard\Plugin;
use KanboardTests\units\Base;

final class PageControllerTest extends Base
{
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->container['hook'] = new Hook();
        $this->container['route']->enable();
        (new Plugin($this->container))->initialize();

        $users = $this->container['userModel'];
        $this->userId = $users->create(['username' => 'palette-user', 'role' => Role::APP_USER]);
        $this->container['userSession']->initialize($users->getById($this->userId));
    }

    public function testIndexGroupsPermittedCommandsAndListsRecentsFirst(): void
    {
        $this->command('example.alpha', 'Alpha example');
        $this->command('example.zeta', 'Zeta example');
        $this->command('example.secret', 'Admin command', ['permission' => Role::APP_ADMIN]);
        $frecency = $this->container['switchboardFrecency'];
        $frecency->record($this->userId, 'example.zeta');
        $frecency->record($this->userId, 'example.secret');

        $html = $this->call('index');

        $this->assertStringContainsString('Example group', $html);
        $this->assertStringContainsString('Alpha example', $html);
        $this->assertStringContainsString('Zeta example', $html);
        $this->assertLessThan(strpos($html, 'Alpha example'), strpos($html, 'Zeta example'));
        $this->assertStringNotContainsString('Admin command', $html);
    }

    public function testPostCommandRendersCsrfAndAFieldPerProperty(): void
    {
        $this->command('example.submit', 'Update example', [
            'target' => [
                'kind' => 'post',
                'controller' => 'ExampleController',
                'action' => 'save',
                'params' => ['plugin' => 'Example'],
            ],
            'input' => ['type' => 'object', 'properties' => [
                'comment' => ['type' => 'string'],
                'state' => ['type' => 'string', 'enum' => ['Open', 'Closed']],
            ]],
        ]);

        $form = $this->xpath($this->call('index'))->query('//form[contains(@action, "ExampleController")]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $form);
        $this->assertSame('post', $form->getAttribute('method'));
        $xpath = new DOMXPath($form->ownerDocument);
        $this->assertSame(1, $xpath->query('.//input[@name="csrf_token" and string-length(@value) > 0]', $form)->length);
        $this->assertSame(1, $xpath->query('.//input[@name="comment"]', $form)->length);
        $this->assertSame(1, $xpath->query('.//select[@name="state"]/option[@value="Closed"]', $form)->length);
    }

    public function testSearchIncludesTasksAndOmitsSensitiveProvidersEvenInTheirContext(): void
    {
        $projectId = $this->container['projectModel']->create(['name' => 'Example project']);
        $this->container['projectUserRoleModel']->addUser($projectId, $this->userId, Role::PROJECT_MEMBER);
        $this->container['taskCreationModel']->create(['title' => 'Example ticket', 'project_id' => $projectId]);
        $sensitiveCalls = 0;
        $this->provider('vault.results', 'Vault private results', static function () use (&$sensitiveCalls): array {
            $sensitiveCalls++;

            return ['entries' => [['title' => 'Secret example', 'url' => '/vault']]];
        }, true);
        $this->provider('example.broken', 'Broken search', static fn() => throw new RuntimeException('private detail'));

        $html = $this->call('index', ['q' => 'example', 'from_plugin' => 'Vault']);

        $this->assertStringContainsString('Example ticket', $html);
        $this->assertStringContainsString('Broken search', $html);
        $this->assertStringContainsString('Search unavailable', $html);
        $this->assertStringNotContainsString('private detail', $html);
        $this->assertStringNotContainsString('Vault private results', $html);
        $this->assertStringNotContainsString('Secret example', $html);
        $this->assertSame(0, $sensitiveCalls);
    }

    public function testConfirmRequiresPostAndCsrf(): void
    {
        foreach ([null, ['id' => 'example.confirm']] as $post) {
            try {
                $this->call('confirm', post: $post);
                $this->fail('Confirmation accepted without a CSRF-protected POST');
            } catch (AccessForbiddenException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testConsequentialCommandRunsOnceAfterConfirmation(): void
    {
        $calls = [];
        $this->command('example.confirm', 'Confirm example', [
            'risk' => 'consequential',
            'input' => ['type' => 'object', 'properties' => ['label' => ['type' => 'string']], 'required' => ['label']],
            'target' => ['kind' => 'handler', 'handler' => static function (array $user, array $ctx, array $input) use (&$calls): array {
                $calls[] = [$ctx['route']['plugin'], $input];

                return ['type' => 'text', 'body' => '<b>Finished</b>'];
            }],
        ]);
        $query = ['from_plugin' => 'Example'];
        $firstForm = $this->xpath($this->call('index', $query))->query('//form[.//input[@name="id" and @value="example.confirm"]]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $firstForm);
        [$firstQuery, $post] = $this->formSubmission($firstForm);
        $this->assertSame($query, array_intersect_key($firstQuery, $query));
        $post['input']['label'] = '<label> &amp; "value"';

        $html = $this->call('confirm', $firstQuery, $post);
        $this->assertSame([], $calls);
        $this->assertStringContainsString('Confirm example', $html);
        $form = $this->xpath($html)->query('//form[.//input[@name="confirm_token"]]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $form);
        [$nextQuery, $confirmation] = $this->formSubmission($form);
        $this->assertNotEmpty($confirmation['confirm_token'] ?? '');
        $this->assertSame($post['input'], $confirmation['input']);

        $html = $this->call('confirm', $nextQuery, $confirmation);

        $this->assertSame([['Example', $post['input']]], $calls);
        $this->assertStringContainsString('&lt;b&gt;Finished&lt;/b&gt;', $html);
    }

    public function testSearchDiscardsLateResultsAndContinuesWithOtherProviders(): void
    {
        $now = 0;
        $this->provider('example.slow', 'Slow search', static function () use (&$now): array {
            $now += 2_000_000_000;

            return ['entries' => [['title' => 'Late result', 'url' => '/late']]];
        });
        $this->provider('example.next', 'Next search', static fn(): array => ['entries' => [
            ['title' => 'Next result', 'url' => '/next'],
            ['title' => 'Unsafe result', 'url' => 'javascript:doSomething()'],
        ]]);
        $clock = static function () use (&$now): int {
            return $now;
        };

        $html = $this->call('index', ['q' => 'example'], clock: $clock);

        $this->assertStringContainsString('Slow search', $html);
        $this->assertStringContainsString('Search unavailable', $html);
        $this->assertStringNotContainsString('Late result', $html);
        $this->assertStringContainsString('Next result', $html);
        $this->assertStringNotContainsString('Unsafe result', $html);
    }

    private function formSubmission(DOMElement $form): array
    {
        parse_str(parse_url($form->getAttribute('action'), PHP_URL_QUERY) ?? '', $query);
        $values = [];
        $xpath = new DOMXPath($form->ownerDocument);
        foreach ($xpath->query('.//input[@type="hidden"]', $form) as $input) {
            $values[] = urlencode($input->getAttribute('name')).'='.urlencode($input->getAttribute('value'));
        }
        parse_str(implode('&', $values), $post);

        return [$query, $post];
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument();
        $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);

        return new DOMXPath($document);
    }

    private function call(string $action, array $query = [], ?array $post = null, ?callable $clock = null): string
    {
        $this->container['request'] = new Request(
            $this->container, ['REQUEST_METHOD' => $post === null ? 'GET' : 'POST'], $query, $post ?? []
        );
        $html = '';
        $response = $this->createMock(Response::class);
        $response->method('html')->willReturnCallback(static function (string $body) use (&$html): void {
            $html = $body;
        });
        $this->container['response'] = $response;

        (new PageController($this->container, $clock))->$action();

        return $html;
    }

    private function command(string $id, string $title, array $overrides = []): void
    {
        $this->container['hook']->on('switchboard:commands', static fn(): array => [$overrides + [
            'id' => $id,
            'title' => $title,
            'group' => 'Example group',
            'target' => ['kind' => 'route', 'controller' => 'DashboardController', 'action' => 'show'],
        ]]);
    }

    private function provider(string $id, string $title, callable $search, bool $sensitive = false): void
    {
        $this->container['hook']->on('switchboard:search', static fn(): array => [[
            'id' => $id,
            'title' => $title,
            'prefix' => '!',
            'order' => static fn(): int => 40,
            'search' => $search,
            'sensitive' => $sensitive,
        ]]);
    }
}
