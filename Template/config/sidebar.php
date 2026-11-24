<?php if ($this->user->isAdmin()): ?>
    <li <?= $this->app->checkMenuSelection('SettingsController', 'admin', 'Switchboard') ?>>
        <?= $this->url->link(t('Switchboard'), 'SettingsController', 'admin', ['plugin' => 'Switchboard']) ?>
    </li>
<?php endif ?>
