<?php

namespace Kanboard\Plugin\Switchboard\Model;

/** The one rule for command and provider ids: lowercase, dotted, `vendor.name`. */
final class CommandId
{
    public static function isValid(string $id): bool
    {
        return preg_match('/^[a-z0-9]+(\.[a-z0-9_-]+)+$/D', $id) === 1;
    }
}
