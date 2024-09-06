<?php

declare(strict_types=1);

namespace NGSOFT\Command;

interface ExitCode
{
    public const COMMAND_SUCCESS        = 0;
    public const COMMAND_FAILURE        = 1;
    public const COMMAND_INVALID        = 2;
    public const COMMAND_CANNOT_EXECUTE = 126;
    public const COMMAND_NOT_FOUND      = 127;
}
