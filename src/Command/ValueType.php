<?php

declare(strict_types=1);

namespace NGSOFT\Command;

use NGSOFT\Enums\EnumTrait;

enum ValueType: string
{
    use EnumTrait;

    case None    = 'null';
    case String  = 'string';
    case Boolean = 'bool';
    case Integer = 'int';
    case Float   = 'float';

    public function convertValue(string $value): mixed
    {
        try
        {
            return match ($this->value)
            {
                'null'   => null,
                'string' => $value,
                default  => json_decode(str_replace(',', '.', $value), flags: JSON_THROW_ON_ERROR),
            };
        } catch (\JsonException)
        {
        }

        return $value;
    }

    public function checkValue(mixed $value): bool
    {
        $method = 'is_' . $this->value;
        return $method($value);
    }

    public function getDefaultValue(): mixed
    {
        return [
            'null'   => null,
            'string' => '',
            'bool'   => false,
            'int'    => 0,
            'float'  => floatval(0),
        ][$this->value];
    }
}
