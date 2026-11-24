<?php

namespace Kanboard\Plugin\Switchboard\Controller;

use Closure;
use Kanboard\Controller\BaseController;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Plugin\Switchboard\Model\Context;
use Kanboard\Plugin\Switchboard\Model\ProviderFailed;
use Kanboard\Plugin\Switchboard\Model\UnknownProvider;
use Pimple\Container;

final class SwitchboardPageController extends BaseController
{
    private const SEARCH_BUDGET_NS = 2_000_000_000;

    private readonly Closure $monotonicClock;

    /** The injectable clock returns monotonic nanoseconds. */
    public function __construct(Container $container, ?callable $monotonicClock = null)
    {
        parent::__construct($container);
        $this->monotonicClock = $monotonicClock === null
            ? static fn(): int => hrtime(true)
            : Closure::fromCallable($monotonicClock);
    }

    public function index(): void
    {
        $user = $this->userSession->getAll();
        $ctx = Context::fromRequest($this->container);
        $params = $this->contextParams($ctx);
        $registry = $this->container['switchboardRegistry'];
        $commands = $registry->permitted($user, $ctx);
        $recent = array_flip($this->container['switchboardFrecency']->recent($user['id']));
        usort($commands, static fn(array $a, array $b): int =>
            ($recent[$a['id']] ?? PHP_INT_MAX) <=> ($recent[$b['id']] ?? PHP_INT_MAX));

        $groups = [];
        foreach ($commands as $command) {
            $groups[$command['group']][] = $registry->toClient($command);
        }

        $query = $this->request->getStringParam('q');
        $query = is_string($query) && mb_check_encoding($query, 'UTF-8') ? trim($query) : '';
        $pageUrl = $this->url('index');
        parse_str(parse_url($pageUrl, PHP_URL_QUERY) ?? '', $routingParams);

        $this->renderPage('index', [
            'groups' => $groups,
            'searchAction' => explode('?', $pageUrl, 2)[0],
            'searchParams' => $routingParams + $params,
            'query' => $query,
            'searchGroups' => $query === '' ? [] : $this->search($user, $ctx, $query),
            'confirmUrl' => $this->url('confirm', $params + ['q' => $query]),
        ]);
    }

    public function confirm(): void
    {
        if (!$this->request->isPost()) {
            throw new AccessForbiddenException();
        }
        $this->checkCSRFForm();

        $ctx = Context::fromRequest($this->container);
        $params = $this->contextParams($ctx);
        $query = $this->request->getStringParam('q');
        if (is_string($query) && $query !== '') {
            $params['q'] = $query;
        }
        $backUrl = $this->url('index', $params);
        $id = $this->request->getRawValue('id');
        $token = $this->request->getRawValue('confirm_token');
        $input = $this->request->getRawValue('input') ?? [];
        if (!is_array($input)) {
            $this->flash->failure('invalid input');
            $this->redirect($backUrl);
            return;
        }

        $input = array_filter($input, static fn($value): bool => $value !== '');
        $result = $this->container['switchboardExecutor']->run(
            $this->userSession->getAll(),
            $ctx,
            is_string($id) ? $id : '',
            $input,
            is_string($token) && $token !== '' ? $token : null
        )['body'];

        if (isset($result['confirm'])) {
            $this->renderPage('confirm', [
                'id' => $id,
                'input' => array_filter($input, is_string(...)),
                'confirmation' => $result['confirm'],
                'confirmUrl' => $this->url('confirm', $params),
                'backUrl' => $backUrl,
            ]);
            return;
        }

        if (isset($result['error'])) {
            $this->flash->failure($result['error']);
            $stepUpUrl = $result['url'] ?? null;
            $this->redirect($this->isWebUrl($stepUpUrl) ? $stepUpUrl : $backUrl);
            return;
        }

        switch ($result['type']) {
            case 'navigate':
            case 'modal':
                if ($this->isWebUrl($result['url'])) {
                    $this->redirect($result['url']);
                } else {
                    $this->flash->failure('command failed');
                    $this->redirect($backUrl);
                }
                break;
            case 'toast':
                $this->flash->success($result['message']);
                $this->redirect($backUrl);
                break;
            case 'list':
                $result['items'] = array_filter($result['items'], $this->hasWebUrl(...));
                $this->renderPage('result', ['result' => $result, 'backUrl' => $backUrl]);
                break;
            case 'text':
                if (!$this->isWebUrl($result['continue_url'] ?? null)) {
                    unset($result['continue_url']);
                }
                $this->renderPage('result', ['result' => $result, 'backUrl' => $backUrl]);
                break;
        }
    }

    private function search(array $user, array $ctx, string $query): array
    {
        // Search on this page is global. A source plugin must not opt it into sensitive results.
        $ctx['route']['plugin'] = '';
        $providers = $this->container['switchboardProviders'];
        $groups = [];
        foreach ($providers->listFor($user, $ctx, null) as $provider) {
            $started = ($this->monotonicClock)();
            $entries = null;
            try {
                $result = $providers->search($provider['id'], $user, $ctx, $query, null, 10);
                // The provider runs synchronously, so the cutoff applies to its results.
                if (($this->monotonicClock)() - $started < self::SEARCH_BUDGET_NS) {
                    $entries = array_filter($result['entries'], $this->hasWebUrl(...));
                }
            } catch (ProviderFailed | UnknownProvider) {
                // Continue to the next provider and show the generic error for this group.
            }
            $groups[] = ['title' => $provider['title'], 'entries' => $entries];
        }

        return $groups;
    }

    private function hasWebUrl(array $item): bool
    {
        return $this->isWebUrl($item['url']);
    }

    private function isWebUrl(mixed $url): bool
    {
        if (!is_string($url) || trim($url) === '' || preg_match('/[\x00-\x1f\x7f]/', $url)) {
            return false;
        }
        $scheme = parse_url(trim($url), PHP_URL_SCHEME);

        return $scheme === null || (is_string($scheme) && in_array(strtolower($scheme), ['http', 'https'], true));
    }

    private function contextParams(array $ctx): array
    {
        return array_filter([
            'project_id' => $ctx['project_id'],
            'task_id' => $ctx['task_id'],
            'from_controller' => $ctx['route']['controller'],
            'from_action' => $ctx['route']['action'],
            'from_plugin' => $ctx['route']['plugin'],
        ], static fn($value): bool => $value !== null && $value !== '');
    }

    private function url(string $action, array $params = []): string
    {
        return $this->helper->url->to('SwitchboardPageController', $action, ['plugin' => 'Switchboard'] + $params);
    }

    private function renderPage(string $template, array $params): void
    {
        $this->response->html($this->helper->layout->app('Switchboard:page/'.$template, ['title' => t('Switchboard')] + $params));
    }

    private function redirect(string $url): void
    {
        $this->response->withStatusCode(303)->redirect($url);
    }
}
