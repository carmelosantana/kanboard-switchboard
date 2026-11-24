<section id="main">
    <h1><?= t('Command result') ?></h1>
    <?php if ($result['type'] === 'list'): ?>
        <?php if ($result['items'] === []): ?>
            <p><?= t('No results') ?></p>
        <?php else: ?>
            <ul>
                <?php foreach ($result['items'] as $item): ?>
                    <li>
                        <a href="<?= htmlspecialchars($item['url']) ?>"><?= $this->text->e($item['title']) ?></a>
                        <?= $this->text->e($item['subline'] ?? '') ?>
                    </li>
                <?php endforeach ?>
            </ul>
        <?php endif ?>
    <?php else: ?>
        <p><?= nl2br($this->text->e($result['body'])) ?></p>
        <?php if (isset($result['continue_url'])): ?>
            <p><a href="<?= htmlspecialchars($result['continue_url']) ?>"><?= t('Continue') ?></a></p>
        <?php endif ?>
    <?php endif ?>
    <p><a href="<?= $this->text->e($backUrl) ?>"><?= t('Search and commands') ?></a></p>
</section>
