<?php

use Kanboard\Core\Plugin\Hook;
use Kanboard\Core\Plugin\Loader;
use Kanboard\Plugin\Switchboard\Model\Frecency;
use Kanboard\Plugin\Switchboard\Plugin;
use KanboardTests\units\Base;

final class FrecencyTest extends Base
{
    private const KEY = 'switchboard.recent';
    private const HALF_LIFE = 14 * 86400;

    private Frecency $frecency;
    private int $userId;
    private int $now = 1_800_000_000;

    protected function setUp(): void
    {
        parent::setUp();
        (new Loader($this->container))->loadSchema('Switchboard');

        $this->userId = $this->container['userModel']->create(['username' => 'frecency-user']);
        $this->frecency = new Frecency($this->container, fn(): int => $this->now);
    }

    public function testPluginRegistersFrecencyService(): void
    {
        $this->container['hook'] = new Hook();
        (new Plugin($this->container))->initialize();

        $this->assertInstanceOf(Frecency::class, $this->container['switchboardFrecency']);
    }

    public function testRecordingTheSameCommandIncrementsCountAndUpdatesLastUse(): void
    {
        $this->frecency->record($this->userId, 'plugin.command');
        $this->now += 60;
        $this->frecency->record($this->userId, 'plugin.command');

        $this->assertSame([
            ['command_id' => 'plugin.command', 'count' => 2, 'last_used' => $this->now],
        ], $this->storedEntries());
        $this->assertSame(['plugin.command' => 2.0], $this->frecency->scores($this->userId));
    }

    public function testScoresHaveAFourteenDayHalfLifeAndRecentReturnsHighestScoresFirst(): void
    {
        $this->frecency->record($this->userId, 'plugin.older');
        $this->now += self::HALF_LIFE;
        $this->frecency->record($this->userId, 'plugin.current');

        $scores = $this->frecency->scores($this->userId);
        $this->assertSame(1.0, $scores['plugin.current']);
        $this->assertSame(0.5, $scores['plugin.older']);
        $this->assertSame(['plugin.current', 'plugin.older'], $this->frecency->recent($this->userId));
        $this->assertSame(['plugin.current'], $this->frecency->recent($this->userId, 1));
    }

    public function testRecordingTheFiftyFirstCommandEvictsTheLowestScore(): void
    {
        for ($use = 0; $use < 5; $use++) {
            $this->frecency->record($this->userId, 'plugin.oldest');
        }
        $this->now += self::HALF_LIFE;
        $this->frecency->record($this->userId, 'plugin.lowest');
        $this->now += self::HALF_LIFE;
        for ($index = 1; $index <= 48; $index++) {
            $this->frecency->record($this->userId, 'plugin.command-'.$index);
        }

        $this->assertCount(50, $this->storedEntries());
        $this->frecency->record($this->userId, 'plugin.newest');

        $ids = array_column($this->storedEntries(), 'command_id');
        $this->assertCount(50, $ids);
        $this->assertContains('plugin.oldest', $ids);
        $this->assertContains('plugin.newest', $ids);
        $this->assertNotContains('plugin.lowest', $ids);
        $this->assertCount(8, $this->frecency->recent($this->userId));
    }

    public function testCorruptLegacyMetadataIsRemovedAndTheNextRecordWorks(): void
    {
        $this->container['userMetadataModel']->save($this->userId, [self::KEY => '{']);

        $this->assertSame([], $this->frecency->scores($this->userId));
        $this->assertSame([], $this->frecency->recent($this->userId));

        $this->assertFalse($this->container['userMetadataModel']->exists($this->userId, self::KEY));
        $this->frecency->record($this->userId, 'plugin.repaired');

        $this->assertSame([
            ['command_id' => 'plugin.repaired', 'count' => 1, 'last_used' => $this->now],
        ], $this->storedEntries());
    }

    public function testFiftySixtyCharacterIdsRoundTrip(): void
    {
        $expected = [];
        for ($index = 0; $index < 50; $index++) {
            $id = sprintf('plugin.%051d%02d', 0, $index);
            $expected[$id] = 1.0;
            $this->frecency->record($this->userId, $id);
        }

        $this->assertCount(50, $this->storedEntries());
        $this->assertEquals($expected, $this->frecency->scores($this->userId));
    }

    public function testOversizedCommandIdDoesNotPreventRecordingOtherCommands(): void
    {
        $this->frecency->record($this->userId, 'plugin.'.str_repeat('a', 185));
        $this->frecency->record($this->userId, 'plugin.'.str_repeat('b', 184));

        $this->assertSame(['plugin.'.str_repeat('b', 184)], $this->frecency->recent($this->userId));
    }

    public function testClearEmptiesRecentCommands(): void
    {
        $this->frecency->record($this->userId, 'plugin.command');
        $this->container['userMetadataModel']->save($this->userId, [self::KEY => '[]']);
        $this->frecency->clear($this->userId);

        $this->assertFalse($this->container['userMetadataModel']->exists($this->userId, self::KEY));
        $this->assertSame([], $this->frecency->scores($this->userId));
        $this->assertSame([], $this->storedEntries());
    }

    public function testRecordingAndClearingAreIsolatedPerUser(): void
    {
        $otherUserId = $this->container['userModel']->create(['username' => 'other-frecency-user']);
        $this->frecency->record($this->userId, 'plugin.command');
        $this->assertSame([], $this->frecency->scores($otherUserId));

        $this->frecency->record($otherUserId, 'plugin.command');
        $this->frecency->record($otherUserId, 'plugin.command');
        $this->assertSame(['plugin.command' => 1.0], $this->frecency->scores($this->userId));

        $this->frecency->clear($this->userId);
        $this->assertSame(['plugin.command' => 2.0], $this->frecency->scores($otherUserId));
    }

    private function storedEntries(): array
    {
        return $this->container['db']->table('switchboard_recent')
            ->eq('user_id', $this->userId)
            ->columns('command_id', 'count', 'last_used')
            ->findAll();
    }
}
