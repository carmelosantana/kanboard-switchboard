<section id="main">
    <h1><?= t('Confirm command') ?></h1>
    <p><?= $this->text->e($confirmation['message']) ?></p>
    <form method="post" action="<?= $this->text->e($confirmUrl) ?>">
        <?= $this->form->csrf() ?>
        <input type="hidden" name="id" value="<?= $this->text->e($id) ?>">
        <input type="hidden" name="confirm_token" value="<?= $this->text->e($confirmation['token']) ?>">
        <?php foreach ($input as $name => $value): ?>
            <input type="hidden" name="input[<?= htmlspecialchars((string) $name) ?>]" value="<?= htmlspecialchars($value) ?>">
        <?php endforeach ?>
        <button type="submit" class="btn btn-blue"><?= t('Confirm') ?></button>
        <a href="<?= $this->text->e($backUrl) ?>"><?= t('Cancel') ?></a>
    </form>
</section>
