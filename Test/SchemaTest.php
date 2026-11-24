<?php

use Kanboard\Core\Plugin\Loader;
use Kanboard\Core\Plugin\SchemaHandler;
use KanboardTests\units\Base;

final class SchemaTest extends Base
{
    public function testVersionOneSupportsRoundTripAndRejectsDuplicateUserCommand(): void
    {
        (new Loader($this->container))->loadSchema('Switchboard');
        $this->assertSame(1, (new SchemaHandler($this->container))->getSchemaVersion('Switchboard'));

        $db = $this->container['db'];
        $pdo = $db->getConnection();
        $count = $db->escapeIdentifier('count');
        $insert = $pdo->prepare('INSERT INTO switchboard_recent (user_id, command_id, last_used) VALUES (?, ?, ?)');
        $insert->execute([1, 'plugin.command', 1_800_000_000]);
        $insert->execute([2, 'plugin.command', 1_800_000_001]);

        $row = $pdo->query("SELECT command_id, $count, last_used FROM switchboard_recent WHERE user_id = 1")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('plugin.command', $row['command_id']);
        $this->assertSame(0, (int) $row['count']);
        $this->assertSame(1_800_000_000, (int) $row['last_used']);

        $this->expectException(PDOException::class);
        $insert->execute([1, 'plugin.command', 1_800_000_002]);
    }
}
