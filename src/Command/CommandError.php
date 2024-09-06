<?php

declare(strict_types=1);

namespace NGSOFT\Command;

class CommandError extends \RuntimeException
{
    public function __construct(int $code = ExitCode::COMMAND_CANNOT_EXECUTE, ?string $message = null)
    {
        $message ??= match ($code)
        {
            ExitCode::COMMAND_CANNOT_EXECUTE => 'Command cannot execute',
            ExitCode::COMMAND_INVALID        => 'Invalid command',
            ExitCode::COMMAND_FAILURE        => 'Command failure',
            ExitCode::COMMAND_NOT_FOUND      => 'Command not found',
            default                          => 'An error occurred while executing command',
        };

        parent::__construct($message, $code);
    }
}
