<?php

namespace Kanboard\Plugin\Switchboard\Controller;

use Kanboard\Controller\BaseController;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Plugin\Switchboard\Model\Context;
use Kanboard\Plugin\Switchboard\Model\ProviderFailed;
use Kanboard\Plugin\Switchboard\Model\UnknownProvider;
use stdClass;

final class ApiController extends BaseController
{
    public function commands(): void
    {
        $user = $this->userSession->getAll();
        $ctx = Context::fromRequest($this->container);
        $registry = $this->container['switchboardRegistry'];

        $this->response->json([
            'commands' => array_map($registry->toClient(...), $registry->permitted($user, $ctx)),
            'recent' => $this->container['switchboardFrecency']->recent($user['id']),
        ]);
    }

    public function providers(): void
    {
        $ctx = Context::fromRequest($this->container);
        $providers = $this->container['switchboardProviders']->listFor($this->userSession->getAll(), $ctx, $this->prefix());

        $this->response->json(['providers' => $providers]);
    }

    public function search(): void
    {
        $ctx = Context::fromRequest($this->container);
        $ctx['prefix'] = $this->prefix();
        $requestedLimit = $this->queryString('limit');
        $limit = $requestedLimit !== null && preg_match('/^-?[0-9]+$/D', $requestedLimit)
            ? max(1, min(10, (int) $requestedLimit))
            : 10;

        try {
            $result = $this->container['switchboardProviders']->search(
                $this->queryString('provider') ?? '',
                $this->userSession->getAll(),
                $ctx,
                $this->queryString('term') ?? '',
                $this->queryString('cursor'),
                $limit
            );
        } catch (UnknownProvider) {
            $this->response->json(['error' => 'unknown provider'], 404);
            return;
        } catch (ProviderFailed) {
            $this->response->json(['error' => 'provider failed'], 500);
            return;
        }

        $this->response->json($result);
    }

    public function run(): void
    {
        $this->checkPost();

        $rawInput = $this->request->getRawValue('input') ?? '{}';
        $input = is_string($rawInput) ? json_decode($rawInput) : null;
        if (!$input instanceof stdClass) {
            $this->response->json(['error' => 'invalid input', 'field' => 'input'], 422);
            return;
        }

        $id = $this->request->getRawValue('id');
        $confirmToken = $this->request->getRawValue('confirm_token');
        $result = $this->container['switchboardExecutor']->run(
            $this->userSession->getAll(),
            Context::fromRequest($this->container),
            is_string($id) ? $id : '',
            (array) $input,
            is_string($confirmToken) && $confirmToken !== '' ? $confirmToken : null
        );

        $this->response->json($result['body'], $result['status']);
    }

    public function used(): void
    {
        $this->checkPost();

        $id = $this->request->getRawValue('id');
        if (is_string($id)) {
            $user = $this->userSession->getAll();
            $ctx = Context::fromRequest($this->container);
            if ($this->container['switchboardRegistry']->find($id, $user, $ctx) !== null) {
                $this->container['switchboardFrecency']->record($user['id'], $id);
            }
        }

        $this->response->status(204);
    }

    private function checkPost(): void
    {
        if (!$this->request->isPost()) {
            throw new AccessForbiddenException();
        }
        $this->checkReusableCSRFParam();
    }

    private function queryString(string $name): ?string
    {
        $value = $this->request->getStringParam($name, null);

        return is_string($value) ? $value : null;
    }

    private function prefix(): ?string
    {
        $prefix = $this->queryString('prefix');

        return $prefix !== null && mb_strlen($prefix, 'UTF-8') <= 2 ? $prefix : null;
    }
}
