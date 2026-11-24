<?php

namespace Kanboard\Plugin\Switchboard\Model;

use Closure;
use Kanboard\Core\Base;
use Pimple\Container;

final class Frecency extends Base
{
    private const KEY = 'switchboard.recent';
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
        $entries = $this->entries($userId);
        $now = ($this->clock)();
        $entries[$commandId] = [
            'id' => $commandId,
            'count' => ($entries[$commandId]['count'] ?? 0) + 1,
            'last' => $now,
        ];

        if (count($entries) > self::CAPACITY) {
            $scores = $this->scoreEntries($entries, $now);
            arsort($scores, SORT_NUMERIC);
            $entries = array_intersect_key($entries, array_slice($scores, 0, self::CAPACITY, true));
        }

        $this->userMetadataModel->save($userId, [
            self::KEY => json_encode(array_values($entries), JSON_THROW_ON_ERROR),
        ]);
    }

    /** @return array<string, float> */
    public function scores(int $userId): array
    {
        return $this->scoreEntries($this->entries($userId), ($this->clock)());
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
        $this->userMetadataModel->remove($userId, self::KEY);
    }

    private function entries(int $userId): array
    {
        $stored = json_decode($this->userMetadataModel->get($userId, self::KEY, '[]'));
        $entries = [];
        foreach (is_array($stored) ? $stored : [] as $entry) {
            if (is_string($entry->id ?? null) && is_int($entry->count ?? null) && is_int($entry->last ?? null)) {
                $entries[$entry->id] = ['id' => $entry->id, 'count' => $entry->count, 'last' => $entry->last];
            }
        }

        return $entries;
    }

    private function scoreEntries(array $entries, int $now): array
    {
        return array_map(
            static fn(array $entry): float => $entry['count'] * 0.5 ** (($now - $entry['last']) / self::HALF_LIFE),
            $entries
        );
    }
}
