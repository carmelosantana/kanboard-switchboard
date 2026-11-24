<div class="page-header"><h2><?= t('Switchboard') ?></h2></div>
<form method="post" action="<?= $this->url->href('SwitchboardSettingsController', 'adminSave', ['plugin' => 'Switchboard']) ?>">
    <?= $this->form->csrf() ?>
    <fieldset>
        <legend><?= t('External search providers') ?></legend>
        <?php if ($providers === []): ?>
            <p><?= t('No external search providers registered') ?></p>
        <?php endif ?>
        <?php foreach ($providers as $id => $providerTitle): ?>
            <label>
                <input type="checkbox" name="providers[]" value="<?= $this->text->e($id) ?>"
                       <?= in_array($id, $enabled, true) ? 'checked' : '' ?>>
                <?= $this->text->e($providerTitle) ?>
            </label>
        <?php endforeach ?>
    </fieldset>
    <div class="form-actions">
        <button type="submit" class="btn btn-blue"><?= t('Save') ?></button>
    </div>
</form>
