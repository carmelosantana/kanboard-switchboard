<?php

namespace Kanboard\Plugin\Switchboard\Model;

use PicoDb\Database;

/**
 * A case-insensitive "contains" condition whose %, _ and ! match themselves.
 *
 * PicoDb's ilike() has no ESCAPE clause and SQLite has no default escape character, so the
 * condition is built here with `!` as the escape (valid on SQLite, MySQL and Postgres) and the
 * pattern quoted by PDO.
 */
final class LikeTerm
{
    public static function contains(Database $db, string $column, string $term): string
    {
        $pattern = '%'.strtr($term, ['!' => '!!', '%' => '!%', '_' => '!_']).'%';

        return $db->escapeIdentifier($column).' '.$db->getDriver()->getOperator('ILIKE').' '
            .$db->getConnection()->quote($pattern)." ESCAPE '!'";
    }
}
