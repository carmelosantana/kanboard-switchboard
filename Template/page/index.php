<section id="main">
    <h1><?= t('Switchboard') ?></h1>
    <form method="get" action="<?= $this->text->e($searchAction) ?>">
        <?php foreach ($searchParams as $name => $value): ?>
            <input type="hidden" name="<?= $this->text->e($name) ?>" value="<?= $this->text->e($value) ?>">
        <?php endforeach ?>
        <label for="switchboard-query"><?= t('Search') ?></label>
        <input type="search" id="switchboard-query" name="q" value="<?= htmlspecialchars($query) ?>" placeholder="<?= t('Type a command or search…') ?>">
        <button type="submit" class="btn"><?= t('Search') ?></button>
    </form>

    <?php foreach ($searchGroups as $group): ?>
        <h2><?= $this->text->e($group['title']) ?></h2>
        <?php if ($group['entries'] === null): ?>
            <p><?= t('Search unavailable') ?></p>
        <?php elseif ($group['entries'] === []): ?>
            <p><?= t('No results') ?></p>
        <?php else: ?>
            <ul>
                <?php foreach ($group['entries'] as $entry): ?>
                    <li>
                        <a href="<?= htmlspecialchars($entry['url']) ?>"><?= $this->text->e($entry['title']) ?></a>
                        <?= $this->text->e($entry['subline'] ?? '') ?>
                    </li>
                <?php endforeach ?>
            </ul>
        <?php endif ?>
    <?php endforeach ?>

    <?php foreach ($groups as $group => $commands): ?>
        <h2><?= $this->text->e($group) ?></h2>
        <ul>
            <?php foreach ($commands as $command): ?>
                <?php $kind = $command['target']['kind']; ?>
                <li>
                    <?php if ($kind === 'route' || $kind === 'modal'): ?>
                        <a href="<?= $this->text->e($command['url']) ?>"><?= $this->text->e($command['title']) ?></a>
                    <?php elseif ($kind === 'key'): ?>
                        <?= $this->text->e($command['title']) ?>
                        <kbd><?= $this->text->e($command['shortcut'] ?? $command['target']['key']) ?></kbd>
                    <?php else: ?>
                        <form method="post" action="<?= $this->text->e($command['url'] ?? $confirmUrl) ?>">
                            <?= $this->form->csrf() ?>
                            <?php if ($kind === 'handler'): ?>
                                <input type="hidden" name="id" value="<?= $this->text->e($command['id']) ?>">
                            <?php endif ?>
                            <?php foreach ($command['input']['properties'] ?? [] as $property => $rules): ?>
                                <?php
                                $name = $kind === 'handler' ? 'input['.$property.']' : $property;
                                $required = in_array($property, $command['input']['required'] ?? [], true);
                                ?>
                                <label>
                                    <?= $this->text->e($property) ?>
                                    <?php if (isset($rules['enum'])): ?>
                                        <select name="<?= $this->text->e($name) ?>" <?= $required ? 'required' : '' ?>>
                                            <?php if (!$required): ?>
                                                <option value=""></option>
                                            <?php endif ?>
                                            <?php foreach ($rules['enum'] as $value): ?>
                                                <option value="<?= htmlspecialchars($value) ?>"><?= $this->text->e($value) ?></option>
                                            <?php endforeach ?>
                                        </select>
                                    <?php else: ?>
                                        <input type="text" name="<?= $this->text->e($name) ?>"
                                               maxlength="<?= (int) ($rules['maxLength'] ?? 500) ?>"
                                               <?= isset($rules['format']) ? 'inputmode="numeric" pattern="[0-9]+"' : '' ?>
                                               <?= $required ? 'required' : '' ?>>
                                    <?php endif ?>
                                </label>
                            <?php endforeach ?>
                            <button type="submit" class="btn"><?= $this->text->e($command['title']) ?></button>
                        </form>
                    <?php endif ?>
                </li>
            <?php endforeach ?>
        </ul>
    <?php endforeach ?>
</section>
