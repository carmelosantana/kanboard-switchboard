<?php if ($this->user->isAdmin()): ?>
    <li <?= $this->app->checkMenuSelection('SwitchboardSettingsController', 'admin', 'Switchboard') ?>>
        <?= $this->url->link(t('Switchboard'), 'SwitchboardSettingsController', 'admin', ['plugin' => 'Switchboard']) ?>
    </li>
<?php endif ?>
