<?php

declare(strict_types=1);

namespace NGSOFT\Command;

use NGSOFT\Enums\EnumTrait;

enum CommandArgument: int
{
    use EnumTrait;

    case Required = 1;
    case Optional = 2;
    case Array    = 4;
}
