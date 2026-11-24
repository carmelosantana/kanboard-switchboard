<?php

namespace Kanboard\Plugin\Switchboard\Model;

use Closure;
use Kanboard\Core\Base;
use Kanboard\Model\ConfigModel;
use PDOException;
use Pimple\Container;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

final class Executor extends Base
{
    private const SECRET_KEY = 'switchboard_secret';

    private readonly Closure $clock;

    public function __construct(Container $c, ?callable $clock = null)
    {
        parent::__construct($c);
        $this->clock = $clock === null ? time(...) : Closure::fromCallable($clock);
    }

    public function run(array $user, array $ctx, string $id, array $input, ?string $confirmToken): array
    {
        $registry = $this->container['switchboardRegistry'];
        $command = $registry->find($id, $user, $ctx);
        if ($command === null) {
            $exists = in_array($id, array_column($registry->all($ctx), 'id'), true);

            return $exists ? $this->error(403, 'forbidden') : $this->error(404, 'unknown command');
        }

        try {
            $input = (new InputValidator($this->container, $user))->validate($command['input'], $input);
        } catch (InvalidInput $error) {
            return $this->error(422, 'invalid input', ['field' => $error->field]);
        }

        try {
            if ($command['step_up']) {
                $adapter = $this->hook->getListeners('switchboard:host')[0] ?? null;
                if ($adapter !== null) {
                    if (!is_array($adapter) || !is_callable($adapter['step_up'] ?? null)) {
                        return $this->commandFailed($id, 'InvalidHostAdapter');
                    }
                    $stepUp = $adapter['step_up']($user);
                    if (!is_array($stepUp) || !is_bool($stepUp['ok'] ?? null)) {
                        return $this->commandFailed($id, 'InvalidHostAdapter');
                    }
                    $url = $stepUp['url'] ?? null;
                    if ((!$stepUp['ok'] || array_key_exists('url', $stepUp))
                        && (!is_string($url) || trim($url) === '')) {
                        return $this->commandFailed($id, 'InvalidHostAdapter');
                    }
                    if (!$stepUp['ok']) {
                        return $this->error(428, 'step up required', ['url' => $url]);
                    }
                }
            }

            if ($command['risk'] === 'consequential' || $command['step_up']) {
                $tokens = new ConfirmToken($this->confirmationSecret(), $this->clock);
                if ($confirmToken === null) {
                    $message = e('Run "%s"?', $command['title']);
                    if ($input !== []) {
                        $message .= ' '.implode(', ', $input);
                    }
                    return [
                        'status' => 200,
                        'body' => ['confirm' => ['message' => $message, 'token' => $tokens->issue($user['id'], $id, $input, $ctx)]],
                    ];
                }
                if (!$tokens->verify($confirmToken, $user['id'], $id, $input, $ctx)) {
                    return $this->error(409, 'invalid confirm');
                }
            }

            $body = $command['target']['kind'] === 'handler'
                ? $this->normalizeResult($command['target']['handler']($user, $ctx, $input))
                : ['type' => 'navigate', 'url' => $registry->toClient($command)['url'] ?? null];
        } catch (Throwable $error) {
            return $this->commandFailed($id, get_class($error));
        }

        try {
            (new Frecency($this->container, $this->clock))->record($user['id'], $id);
        } catch (Throwable $error) {
            // The command already ran; a lost recents entry must not report it as failed.
            $this->logger->warning(sprintf('Switchboard: recording "%s" failed (%s)', $id, get_class($error)));
        }

        return ['status' => 200, 'body' => $body];
    }

    private function error(int $status, string $code, array $details = []): array
    {
        return ['status' => $status, 'body' => ['error' => $code] + $details];
    }

    private function commandFailed(string $id, string $reason): array
    {
        $this->logger->warning(sprintf('Switchboard: command "%s" failed (%s)', $id, $reason));

        return $this->error(500, 'command failed');
    }

    private function normalizeResult(array $result): array
    {
        $type = $result['type'] ?? null;
        return ['type' => $type] + match ($type) {
            'navigate' => $this->resultFields($result, ['url']),
            'toast' => $this->resultFields($result, ['message']) + (is_array($result['undo'] ?? null) ? ['undo' => $result['undo']] : []),
            'list' => [
                'items' => array_map(
                    fn(array $item): array => $this->resultFields($item, ['title', 'url'], ['subline', 'icon']),
                    array_values(array_slice($result['items'] ?? [], 0, 10))
                ),
            ],
            'modal' => $this->resultFields($result, ['url', 'size']),
            'text' => $this->resultFields($result, ['body'], ['continue_url']),
            default => throw new UnexpectedValueException(),
        };
    }

    private function resultFields(array $data, array $required, array $optional = []): array
    {
        $fields = [];
        foreach ($required as $field) {
            if (!is_string($data[$field] ?? null)) {
                throw new UnexpectedValueException();
            }
            $fields[$field] = $data[$field];
        }
        foreach ($optional as $field) {
            if (is_string($data[$field] ?? null)) {
                $fields[$field] = $data[$field];
            }
        }

        return $fields;
    }

    private function confirmationSecret(): string
    {
        $secret = $this->configModel->getOption(self::SECRET_KEY);
        if ($secret !== '') {
            return $secret;
        }

        $candidate = bin2hex(random_bytes(32));
        $pdo = $this->db->getConnection();
        [$table, $key, $value] = $this->db->escapeIdentifierList([ConfigModel::TABLE, 'option', 'value']);
        // First-use requests share one secret. A blind settings upsert could replace the winner.
        try {
            $statement = $pdo->prepare("INSERT INTO $table ($key, $value) VALUES (?, ?)");
            $statement->execute([self::SECRET_KEY, $candidate]);
        } catch (PDOException $error) {
            if (!in_array($error->getCode(), ['23000', '23505'], true)) {
                throw $error;
            }
            $statement = $pdo->prepare("UPDATE $table SET $value = ? WHERE $key = ? AND ($value = '' OR $value IS NULL)");
            $statement->execute([$candidate, self::SECRET_KEY]);
        }

        $secret = $this->configModel->getOption(self::SECRET_KEY);
        if ($secret === '') {
            throw new RuntimeException();
        }

        return $secret;
    }
}
