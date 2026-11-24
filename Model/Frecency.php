<?php

namespace Kanboard\Plugin\Switchboard\Model;

use Closure;
use Kanboard\Core\Base;
use PDO;
use Pimple\Container;
use Throwable;

final class Frecency extends Base
{
    private const TABLE = 'switchboard_recent';
    private const LEGACY_KEY = 'switchboard.recent';
    private const CAPACITY = 50;
    private const HALF_LIFE = 14 * 86400;

    private Closure $clock;

    public function __construct(Container $c, ?callable $clock = null)
    {
        parent::__construct($c);
        $this->clock = $clock === null ? time(...) : Closure::fromCallable($clock);
    }

    public function record(int $userId, string $commandId): void
    {
        if (strlen($commandId) > CommandId::MAX_LENGTH) {
            return;
        }

        $this->write($userId, function (PDO $pdo) use ($userId, $commandId): void {
            $now = ($this->clock)();
            $count = $this->db->escapeIdentifier('count');
            $update = $pdo->prepare(
                "UPDATE switchboard_recent SET $count = $count + 1, last_used = ? WHERE user_id = ? AND command_id = ?"
            );
            $update->execute([$now, $userId, $commandId]);
            if ($update->rowCount() === 0) {
                $insert = $pdo->prepare(
                    "INSERT INTO switchboard_recent (user_id, command_id, $count, last_used) VALUES (?, ?, 1, ?)"
                );
                $insert->execute([$userId, $commandId, $now]);

                $scores = $this->scoreEntries($userId, $now);
                arsort($scores, SORT_NUMERIC);
                $evicted = array_slice(array_keys($scores), self::CAPACITY);
                if ($evicted !== []) {
                    $this->db->table(self::TABLE)->eq('user_id', $userId)->in('command_id', $evicted)->remove();
                }
            }

            $this->removeLegacyMetadata($userId);
        });
    }

    /** @return array<string, float> */
    public function scores(int $userId): array
    {
        $this->removeLegacyMetadata($userId);

        return $this->scoreEntries($userId, ($this->clock)());
    }

    /** @return list<string> */
    public function recent(int $userId, int $limit = 8): array
    {
        $scores = $this->scores($userId);
        arsort($scores, SORT_NUMERIC);

        return array_slice(array_keys($scores), 0, max(0, $limit));
    }

    public function clear(int $userId): void
    {
        $this->write($userId, function () use ($userId): void {
            $this->db->table(self::TABLE)->eq('user_id', $userId)->remove();
            $this->removeLegacyMetadata($userId);
        });
    }

    private function write(int $userId, Closure $change): void
    {
        $pdo = $this->db->getConnection();
        $pdo->beginTransaction();
        try {
            // Lock the user even when their history is empty. SQLite serializes the first write itself.
            if (DB_DRIVER !== 'sqlite') {
                $lock = $pdo->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
                $lock->execute([$userId]);
                $lock->closeCursor();
            }

            $change($pdo);
            $pdo->commit();
        } catch (Throwable $error) {
            $this->db->cancelTransaction();
            throw $error;
        }
    }

    private function removeLegacyMetadata(int $userId): void
    {
        if ($this->userMetadataModel->exists($userId, self::LEGACY_KEY)) {
            $this->userMetadataModel->remove($userId, self::LEGACY_KEY);
        }
    }

    private function scoreEntries(int $userId, int $now): array
    {
        $entries = $this->db->table(self::TABLE)
            ->eq('user_id', $userId)
            ->columns('command_id', 'count', 'last_used')
            ->asc('command_id')
            ->findAll();

        $scores = [];
        foreach ($entries as $entry) {
            $scores[$entry['command_id']] = $entry['count'] * 0.5 ** (($now - $entry['last_used']) / self::HALF_LIFE);
        }

        return $scores;
    }
}
