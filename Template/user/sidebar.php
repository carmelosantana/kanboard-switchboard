<?php if ($this->user->isCurrentUser($user['id'])): ?>
    <li <?= $this->app->checkMenuSelection('SwitchboardSettingsController', 'show', 'Switchboard') ?>>
        <?= $this->url->link(t('Switchboard'), 'SwitchboardSettingsController', 'show', ['plugin' => 'Switchboard']) ?>
    </li>
<?php endif ?>
