<?php

namespace Kanboard\Plugin\Switchboard\Controller;

use Kanboard\Controller\BaseController;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Core\Security\Role;
use Kanboard\Plugin\Switchboard\Model\Context;
use Throwable;

final class SettingsController extends BaseController
{
    private const SINGLE_KEYS = 'switchboard.single_keys';
    private const EXTERNAL_PROVIDERS = 'switchboard_external_providers';

    public function show(): void
    {
        $userId = $this->userSession->getId();
        // MetadataModel::get() substitutes its default for the stored string "0".
        $metadata = $this->userMetadataModel->getAll($userId);
        $this->response->html($this->helper->layout->user('Switchboard:settings/show', [
            'user' => $this->userModel->getById($userId),
            'singleKeys' => ($metadata[self::SINGLE_KEYS] ?? '1') === '1',
        ]));
    }

    public function save(): void
    {
        $this->checkPost();
        $userId = $this->userSession->getId();
        if ($this->request->getRawValue('clear') === '1') {
            $this->container['switchboardFrecency']->clear($userId);
        } else {
            $saved = $this->userMetadataModel->save($userId, [
                self::SINGLE_KEYS => $this->request->getRawValue('single_keys') === '1' ? '1' : '0',
            ]);
            if (!$saved) {
                $this->flash->failure(t('Unable to save your settings.'));
            }
        }
        $this->redirect('show');
    }

    public function admin(): void
    {
        $this->checkAdmin();
        $enabled = json_decode($this->configModel->getOption(self::EXTERNAL_PROVIDERS, '[]'));
        $this->response->html($this->helper->layout->config('Switchboard:settings/admin', [
            'title' => t('Switchboard'),
            'providers' => $this->externalProviders(),
            'enabled' => is_array($enabled) ? $enabled : [],
        ]));
    }

    public function adminSave(): void
    {
        $this->checkAdmin();
        $this->checkPost();
        $requested = $this->request->getRawValue('providers');
        $enabled = array_values(array_filter(
            array_keys($this->externalProviders()),
            static fn(string $id): bool => is_array($requested) && in_array($id, $requested, true)
        ));
        if (!$this->configModel->save([self::EXTERNAL_PROVIDERS => json_encode($enabled, JSON_THROW_ON_ERROR)])) {
            $this->flash->failure(t('Unable to save settings.'));
        }
        $this->redirect('admin');
    }

    private function externalProviders(): array
    {
        // The settings page needs the registered options before permissions and opt-in apply.
        $ctx = Context::fromRequest($this->container);
        $seen = [];
        $external = [];
        foreach ($this->hook->getListeners('switchboard:search') as $listener) {
            try {
                $rows = $listener($ctx);
            } catch (Throwable) {
                $this->logger->warning('Switchboard: skipped provider "" (listener failed)');
                continue;
            }
            foreach (is_array($rows) ? $rows : [] as $row) {
                $id = $row['id'] ?? null;
                $title = $row['title'] ?? null;
                if (!is_string($id) || !preg_match('/^[a-z0-9]+(\.[a-z0-9_-]+)+$/D', $id) || isset($seen[$id])
                    || !is_string($title) || trim($title) === ''
                    || !is_callable($row['search'] ?? null) || !is_callable($row['order'] ?? null)) {
                    continue;
                }
                $seen[$id] = true;
                if (!empty($row['external'])) {
                    $external[$id] = $title;
                }
            }
        }

        return $external;
    }

    private function checkAdmin(): void
    {
        if ($this->userSession->getRole() !== Role::APP_ADMIN) {
            throw new AccessForbiddenException();
        }
    }

    private function checkPost(): void
    {
        if (!$this->request->isPost()) {
            throw new AccessForbiddenException();
        }
        $this->checkCSRFForm();
    }

    private function redirect(string $action): void
    {
        $this->response->withStatusCode(303)->redirect(
            $this->helper->url->to('SettingsController', $action, ['plugin' => 'Switchboard'])
        );
    }
}
