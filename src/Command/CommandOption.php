<?php

declare(strict_types=1);

namespace NGSOFT\Command;

use NGSOFT\Enums\EnumTrait;

enum CommandOption: int
{
    use EnumTrait;

    case ValueRequired = 1;
    case ValueOptional = 2;
    case ValueArray    = 4;
}
