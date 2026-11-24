<?php if (isset($switchboardSingleKeys)): ?>
<div id="switchboard-root"
     data-commands-url="<?= $this->url->href('ApiController', 'commands', ['plugin' => 'Switchboard']) ?>"
     data-providers-url="<?= $this->url->href('ApiController', 'providers', ['plugin' => 'Switchboard']) ?>"
     data-search-url="<?= $this->url->href('ApiController', 'search', ['plugin' => 'Switchboard']) ?>"
     data-run-url="<?= $this->url->href('ApiController', 'run', ['plugin' => 'Switchboard']) ?>"
     data-used-url="<?= $this->url->href('ApiController', 'used', ['plugin' => 'Switchboard'] + $switchboardContext) ?>"
     data-page-url="<?= $this->url->href('PageController', 'index', ['plugin' => 'Switchboard'] + $switchboardContext) ?>"
     data-csrf="<?= $this->text->e($this->app->getToken()->getReusableCSRFToken()) ?>"
     data-single-keys="<?= $switchboardSingleKeys ?>"
     data-project-id="<?= $this->text->e($switchboardContext['project_id'] ?? '') ?>"
     data-task-id="<?= $this->text->e($switchboardContext['task_id'] ?? '') ?>"
     data-from-controller="<?= $this->text->e($switchboardContext['from_controller'] ?? '') ?>"
     data-from-action="<?= $this->text->e($switchboardContext['from_action'] ?? '') ?>"
     data-from-plugin="<?= $this->text->e($switchboardContext['from_plugin'] ?? '') ?>">
    <div data-slot="switchboard-status" role="status"></div>
</div>
<?php endif ?>
