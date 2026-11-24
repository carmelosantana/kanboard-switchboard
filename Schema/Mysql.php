<?php

namespace Kanboard\Plugin\Switchboard\Schema;

use PDO;

const VERSION = 1;

function version_1(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
        CREATE TABLE switchboard_recent (
            user_id INT NOT NULL,
            command_id VARCHAR(191) NOT NULL,
            `count` INT NOT NULL DEFAULT 0,
            last_used INT NOT NULL,
            PRIMARY KEY (user_id, command_id),
            INDEX switchboard_recent_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
}
