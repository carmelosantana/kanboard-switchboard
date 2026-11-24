<?php if ($this->user->isCurrentUser($user['id'])): ?>
    <li <?= $this->app->checkMenuSelection('SettingsController', 'show', 'Switchboard') ?>>
        <?= $this->url->link(t('Switchboard'), 'SettingsController', 'show', ['plugin' => 'Switchboard']) ?>
    </li>
<?php endif ?>
