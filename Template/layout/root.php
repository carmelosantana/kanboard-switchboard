<?php if (isset($switchboardSingleKeys)): ?>
<div id="switchboard-root"
     data-commands-url="<?= $this->url->href('SwitchboardApiController', 'commands', ['plugin' => 'Switchboard']) ?>"
     data-providers-url="<?= $this->url->href('SwitchboardApiController', 'providers', ['plugin' => 'Switchboard']) ?>"
     data-search-url="<?= $this->url->href('SwitchboardApiController', 'search', ['plugin' => 'Switchboard']) ?>"
     data-run-url="<?= $this->url->href('SwitchboardApiController', 'run', ['plugin' => 'Switchboard']) ?>"
     data-used-url="<?= $this->url->href('SwitchboardApiController', 'used', ['plugin' => 'Switchboard'] + $switchboardContext) ?>"
     data-page-url="<?= $this->url->href('SwitchboardPageController', 'index', ['plugin' => 'Switchboard'] + $switchboardContext) ?>"
     data-csrf="<?= $this->text->e($this->app->getToken()->getReusableCSRFToken()) ?>"
     data-form-csrf="<?= $this->text->e($this->app->getToken()->getCSRFToken()) ?>"
     data-single-keys="<?= $switchboardSingleKeys ?>"
     data-project-id="<?= $this->text->e($switchboardContext['project_id'] ?? '') ?>"
     data-task-id="<?= $this->text->e($switchboardContext['task_id'] ?? '') ?>"
     data-from-controller="<?= $this->text->e($switchboardContext['from_controller'] ?? '') ?>"
     data-from-action="<?= $this->text->e($switchboardContext['from_action'] ?? '') ?>"
     data-from-plugin="<?= $this->text->e($switchboardContext['from_plugin'] ?? '') ?>">
    <div data-slot="switchboard-status" role="status"></div>
</div>
<?php endif ?>
