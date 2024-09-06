<?php

declare(strict_types=1);

namespace NGSOFT\Command;

use NGSOFT\Enums\EnumTrait;

enum ProgressStatus: int
{
    use EnumTrait;

    case Start    = 0;
    case Progress = 1;
    case Complete = 2;

    public function getHandlers(array $handlerList): array
    {
        return $handlerList[$this->value] ?? [];
    }

    public function runHandlers(array $handlerList, mixed ...$args): void
    {
        foreach ($this->getHandlers($handlerList) as $handler)
        {
            if (false === $handler(...$args))
            {
                return;
            }
        }
    }
}
