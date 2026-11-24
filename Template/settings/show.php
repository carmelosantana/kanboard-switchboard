<div class="page-header"><h2><?= t('Switchboard') ?></h2></div>
<form method="post" action="<?= $this->url->href('SwitchboardSettingsController', 'save', ['plugin' => 'Switchboard']) ?>">
    <?= $this->form->csrf() ?>
    <label>
        <input type="checkbox" name="single_keys" value="1" <?= $singleKeys ? 'checked' : '' ?>>
        <?= t('Single-key shortcuts') ?>
    </label>
    <div class="form-actions">
        <button type="submit" class="btn btn-blue"><?= t('Save') ?></button>
        <button type="submit" class="btn" name="clear" value="1"><?= t('Clear recent commands') ?></button>
    </div>
</form>
