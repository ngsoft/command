<?php

declare(strict_types=1);

namespace NGSOFT\Command;

use NGSOFT\DataStructure\Map;

class Command implements ExitCode
{
    protected Map $arguments;
    protected Map $options;

    protected Map $handlers;
    protected bool $hasArrayArg      = false;

    protected array $flags           = [];

    protected array $requiredMissing = [];

    protected ?array $parsedValues   = null;

    public function __construct(
        protected string $name,
        protected string $description = '',
        protected ?\Closure $runner = null
    ) {
        $this->handlers  = new Map();
        $this->arguments = new Map();
        $this->options   = new Map();
        $this->doConfigure();
    }

    /**
     * invoked using ConsoleApplication.
     */
    public function __invoke(OutputHelper $helper): int
    {
        $this->parseArguments($_SERVER['argv'] ?? []);

        $args = $this->parsedValues;

        if ($this->runHandlers($helper, $args))
        {
            return self::COMMAND_SUCCESS;
        }

        $this->assertRequired();
        return $this->execute($helper, $args);
    }

    public static function newCommand(string $name, ?\Closure $runner = null): static
    {
        $i         = new static($name);
        $i->runner = $runner;
        return $i;
    }

    public function setRunner(\Closure $runner): static
    {
        $this->runner = $runner;
        return $this;
    }

    public function run(?array $arguments = null): int
    {
        $arguments ??= $_SERVER['argv'] ?? [];
        $this->parseArguments($arguments);
        $args   = $this->parsedValues;

        $helper = new OutputHelper();

        if ($this->runHandlers($helper, $args))
        {
            return self::COMMAND_SUCCESS;
        }
        $this->assertRequired();
        return $this->execute($helper, $args);
    }

    /**
     * Override that method to make a powerful command.
     */
    public function execute(OutputHelper $helper, array $args): int
    {
        if ( ! $this->runner)
        {
            throw new \LogicException(class_basename(static::class) . '::' . __FUNCTION__ . '() Not implemented');
        }
        return call_user_func($this->runner, $helper, $args);
    }

    public function withName(string $name): static
    {
        $new       = clone $this;
        $new->name = $name;
        return $new;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function addArgument(
        string $name,
        CommandArgument $type = CommandArgument::Required,
        ValueType $valueType = ValueType::String,
        mixed $defaultValue = null,
        string $description = ''
    ): static {
        if ( ! $name)
        {
            throw new \InvalidArgumentException("Command argument name can't be empty");
        }

        if ($this->arguments->has($name) || $this->options->has($name))
        {
            throw new \InvalidArgumentException(
                "Command argument '{$name}' already exists as an " .
                ($this->arguments->has($name) ? 'argument.' : 'option.')
            );
        }

        if ( ! $defaultValue instanceof \Closure)
        {
            if (CommandArgument::Array === $type)
            {
                if ($this->hasArrayArg)
                {
                    throw new \LogicException(
                        "Command argument '{$name}' cannot be defined, an array argument already exists"
                    );
                }
                $this->hasArrayArg = true;
                $defaultValue ??= [];

                if ( ! is_array($defaultValue))
                {
                    throw new \InvalidArgumentException(
                        "Command argument '{$name}' default value must be an array."
                    );
                }

                foreach ($defaultValue as $value)
                {
                    if ( ! $valueType->checkValue($value))
                    {
                        $expected = $valueType->value;
                        $given    = get_debug_type($value);
                        throw new \InvalidArgumentException(
                            "Command argument '{$name}' default value contains an invalid value: {$expected}[] expected, {$given}[] given."
                        );
                    }
                }
            } else
            {
                $defaultValue ??= $valueType->getDefaultValue();

                if ( ! $valueType->checkValue($defaultValue))
                {
                    $expected = $valueType->value;
                    $given    = get_debug_type($defaultValue);
                    throw new \InvalidArgumentException(
                        "Command argument '{$name}' default value is an invalid value: {$expected} expected, {$given} given."
                    );
                }
            }
        }
        $this->arguments->add($name, [
            $description,
            $defaultValue,
            $valueType,
            $type,
        ]);

        return $this;
    }

    public function addOption(
        string $name,
        array $flags,
        CommandOption $type = CommandOption::ValueOptional,
        ValueType $valueType = ValueType::String,
        mixed $defaultValue = null,
        string $description = '',
    ): static {
        if ( ! $name)
        {
            throw new \InvalidArgumentException("Command option name can't be empty");
        }

        if ($this->arguments->has($name) || $this->options->has($name))
        {
            throw new \InvalidArgumentException(
                "Command option '{$name}' already exists as an " .
                ($this->arguments->has($name) ? 'argument.' : 'option.')
            );
        }

        if (empty($flags))
        {
            throw new \InvalidArgumentException(
                "Command option '{$name}' must have at least one flag."
            );
        }

        if ( ! $defaultValue instanceof \Closure)
        {
            if (CommandOption::ValueArray === $type)
            {
                $defaultValue ??= [];

                if ( ! is_array($defaultValue))
                {
                    throw new \InvalidArgumentException(
                        "Command option '{$name}' default value must be an array."
                    );
                }

                foreach ($defaultValue as $value)
                {
                    if ( ! $valueType->checkValue($value))
                    {
                        $expected = $valueType->value;
                        $given    = get_debug_type($value);
                        throw new \InvalidArgumentException(
                            "Command option '{$name}' default value contains an invalid value: {$expected}[] expected, {$given}[] given."
                        );
                    }
                }
            } else
            {
                $defaultValue ??= $valueType->getDefaultValue();

                if ( ! $valueType->checkValue($defaultValue))
                {
                    $expected = $valueType->value;
                    $given    = get_debug_type($defaultValue);
                    throw new \InvalidArgumentException(
                        "Command option '{$name}' default value is an invalid value: {$expected} expected, {$given} given."
                    );
                }
            }
        }

        $commandFlags = [];

        foreach ($flags as $flag)
        {
            $flag               = ltrim(trim($flag), '-');

            if (isset($this->flags[$flag]))
            {
                throw new \InvalidArgumentException(
                    "Command flag '{$flag}' already defined."
                );
            }

            if (preg_match('#^\d#', $flag))
            {
                throw new \InvalidArgumentException(
                    "Command flag '{$flag}' cannot begin with a digit."
                );
            }
            $this->flags[$flag] = $name;
            $commandFlags[]     = [$flag, mb_strlen($flag) < 2];
        }

        $this->options->add($name, [
            $description,
            $defaultValue,
            $valueType,
            $type,
            $commandFlags,
        ]);

        return $this;
    }

    /**
     * @return Map<string,array>
     */
    public function getArguments(): Map
    {
        return $this->arguments;
    }

    /**
     * @return Map<string,array>
     */
    public function getOptions(): Map
    {
        return $this->options;
    }

    public function addHandler(string $name, callable $handler): static
    {
        $this->handlers->add($name, $handler);
        return $this;
    }

    protected function parseArguments(array $arguments): void
    {
        static $argToken       = '#^(-{1,2})(\w.*)#',
        $equalsToken           = '#^(.+)=(.+)$#',
        $reNegIntFloat         = '#^-(?:[.,]?\d+|\d+[.,]\d+)$#',
        $nameKey               = 0, // $name
        $valTypeKey            = 2, // $valueType
        $typeKey               = 3; // $type

        $this->parsedValues    = [];

        if ($this->arguments->isEmpty() && $this->options->isEmpty())
        {
            return;
        }

        $required              = [];
        $args                  = []; // args are positional
        $last                  = null;

        foreach ($this->arguments as $name => $item)
        {
            $item[$nameKey] = $name;

            if (CommandArgument::Required === $item[$typeKey])
            {
                $required[$name] = 'argument';
            }

            if (CommandArgument::Array === $item[$typeKey])
            {
                $last = $item;
                continue;
            }
            $args[]         = $item;
        }

        if ($last)
        {
            $args = [...$args, $last];
        }

        $options               = [];

        foreach ($this->options as $name => $item)
        {
            $item[$nameKey] = $name;

            if (CommandOption::ValueRequired === $item[$typeKey])
            {
                $required[$name] = 'option';
            }

            foreach ($item[$typeKey + 1] as list($flag))
            {
                $options[$flag] = $item;
            }
        }

        // this the php script
        array_shift($arguments);
        // this is the command name
        array_shift($arguments);

        $current               = null;
        $positional            = current($args);
        $parsedArgs            = [];

        while (count($arguments))
        {
            $arg = array_shift($arguments);

            if (preg_test($reNegIntFloat, $arg))
            {
                $arg = str_replace(',', '.', $arg);
            } elseif (preg_match($argToken, $arg, $matches))
            {
                if ($current)
                {
                    $this->addParsedArg($current, null);
                }

                list(, $token, $current) = $matches;
                $long                    = '--' === $token;
                $value                   = null;

                if (preg_match($equalsToken, $current, $matches))
                {
                    list(, $current, $value) = $matches;
                }

                $current                 = [$current];

                if ( ! $long)
                {
                    $current = mb_str_split($current[0]);
                }

                if (isset($value))
                {
                    $this->addParsedArg($current, $value);
                    $current = null;
                    continue;
                }

                for ($i = count($current) - 1; $i >= 0; --$i)
                {
                    $flag      = $current[$i];

                    if ( ! isset($options[$flag]))
                    {
                        array_splice($current, $i, 1);
                        continue;
                    }
                    /** @var ValueType $valueType */
                    $valueType = $options[$flag][$valTypeKey];

                    if (ValueType::Boolean === $valueType)
                    {
                        $this->addParsedArg($flag, 'true');
                        array_splice($current, $i, 1);
                    }
                }

                if ( ! count($arguments) && $current)
                {
                    $this->addParsedArg($current, null);
                    $current = null;
                }
                continue;
            }

            if ($current)
            {
                $this->addParsedArg($current, $arg);
                $current = null;
                continue;
            }

            if (is_array($positional))
            {
                /** @var ValueType $valueType */
                $valueType                           = $positional[$valTypeKey];
                $converted                           = $valueType->convertValue($arg);

                if ( ! $valueType->checkValue($converted))
                {
                    $expected = $valueType->value;
                    $given    = get_debug_type($converted);
                    throw new \InvalidArgumentException(
                        "Command argument '{$positional[$nameKey]}' default value is an invalid value: {$expected} expected, {$given} given."
                    );
                }

                if (CommandArgument::Array !== $positional[$typeKey])
                {
                    $parsedArgs[$positional[$nameKey]] = $converted;
                    $positional                        = next($args);
                    continue;
                }

                $parsedArgs[$positional[$nameKey]] ??= [];
                $parsedArgs[$positional[$nameKey]][] = $converted;
            }
        }

        $this->parsedValues += $parsedArgs;

        $this->requiredMissing = [];

        // Check required
        foreach ($required as $name => $type)
        {
            if ( ! array_key_exists($name, $this->parsedValues))
            {
                $this->requiredMissing[$name] = $type;
            }
        }

        // populate default values
        foreach ($this->options as $name => list(, $defaultValue))
        {
            $this->parsedValues[$name] ??= value($defaultValue);
        }

        foreach ($this->arguments as $name => list(, $defaultValue))
        {
            $this->parsedValues[$name] ??= value($defaultValue);
        }
    }

    protected function addParsedArg(array|string $flags, ?string $value): void
    {
        if (is_string($flags))
        {
            $flags = [$flags];
        }

        foreach ($flags as $flag)
        {
            if ($name = $this->flags[$flag] ?? null)
            {
                /**
                 * @var mixed         $defaultValue
                 * @var ValueType     $valueType
                 * @var CommandOption $type
                 */
                list(,
                    $defaultValue,
                    $valueType,
                    $type,
                )                          = $this->options->get($name);

                $current                   = $this->parsedValues[$name] ?? null;

                if (is_string($value))
                {
                    $converted = $valueType->convertValue($value);
                } else
                {
                    $converted = value($defaultValue);
                }

                if ( ! $valueType->checkValue($converted))
                {
                    $pre = 1 === mb_strlen($flag) ? '-' : '--';
                    throw new CommandError(
                        self::COMMAND_INVALID,
                        "Option {$pre}{$flag} is invalid."
                    );
                }

                if (CommandOption::ValueArray === $type)
                {
                    $current ??= [];
                    $current[] = $converted;
                } else
                {
                    $current = $converted;
                }
                $this->parsedValues[$name] = $current;
            }
        }
    }

    /**
     * Override that method to add arguments and options.
     */
    protected function configure(): void {}

    private function runHandlers(OutputHelper $helper, array $args): bool
    {
        foreach ($this->handlers as $name => $handler)
        {
            if (isset($args[$name]))
            {
                $r = $handler($helper, $args, $this);

                if (0 === $r)
                {
                    return true;
                }
            }
        }

        return false;
    }

    private function assertRequired(): void
    {
        foreach ($this->requiredMissing as $name => $type)
        {
            throw new CommandError(
                self::COMMAND_INVALID,
                "Required {$type} '{$name}' does not exist"
            );
        }
    }

    private function doConfigure(): void
    {
        if ( ! $this->name)
        {
            throw new \InvalidArgumentException("Command name can't be empty");
        }

        if ( ! $this instanceof Help)
        {
            Help::addHelpHandler($this);
        }
        $this->configure();
    }
}
