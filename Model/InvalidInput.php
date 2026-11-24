<?php

namespace Kanboard\Plugin\Switchboard\Model;

use InvalidArgumentException;

final class InvalidInput extends InvalidArgumentException
{
    public function __construct(public readonly string $field)
    {
        parent::__construct($field);
    }
}
