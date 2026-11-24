<?php

namespace Kanboard\Plugin\Switchboard\Model;

/** The one rule for command and provider ids: lowercase, dotted, `vendor.name`, at most 191 bytes. */
final class CommandId
{
    /** The recents table's key length (switchboard_recent.command_id). */
    public const MAX_LENGTH = 191;

    public static function isValid(string $id): bool
    {
        return strlen($id) <= self::MAX_LENGTH && preg_match('/^[a-z0-9]+(\.[a-z0-9_-]+)+$/D', $id) === 1;
    }
}
