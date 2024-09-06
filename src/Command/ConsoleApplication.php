<?php

declare(strict_types=1);

namespace NGSOFT\Command;

use NGSOFT\DataStructure\Map;
use NGSOFT\IO;
use NGSOFT\IO\GetOpt;

class ConsoleApplication
{
    protected const FALLBACK_NAME = '__fallback__';
    protected Map $definitions;

    protected IO $io;

    protected GetOpt $opt;

    protected OutputHelper $helper;

    public function __construct()
    {
        $this->helper      = new OutputHelper();
        $this->io          = $this->helper->getIo();
        $this->opt         = $this->io->parseOpt();
        $this->definitions = new Map();
        $this->setFallbackDefinition($help = new Help());
        $help->setDefinitions($this->definitions);
    }

    public function run(?string $command = null): never
    {
        $helper  = $this->helper;

        $command ??= $this->opt[1] ?? '';

        $command = ltrim($command, '-');

        try
        {
            if ( ! empty($command) && ! $this->definitions->has($command))
            {
                throw new CommandError(ExitCode::COMMAND_NOT_FOUND, "Command '{$command}' not defined");
            }

            $runner = $this->definitions->get($command) ?? $this->definitions->get(self::FALLBACK_NAME);

            $result = $runner($helper);

            if ( ! is_int($result))
            {
                $result = ExitCode::COMMAND_SUCCESS;
            }

            if ($result > 0)
            {
                throw new CommandError($result);
            }
        } catch (CommandError $err)
        {
            $helper->err(
                $this->helper->block(
                    $err->getMessage(),
                    'bg:red',
                ),
                "\n"
            );

            $result = $err->getCode();
        } catch (\Throwable $err)
        {
            $helper->err(
                $this->helper->block(
                    sprintf("%s has been thrown:\n%s", get_class($err), $err->getMessage()),
                    'bg:red',
                ),
                "\n"
            );
            $result = ExitCode::COMMAND_FAILURE;
        }

        exit($result);
    }

    public function add(string $name, callable $runner): static
    {
        $this->definitions->add($name, $runner);
        return $this;
    }

    public function addMany(array $definitions): static
    {
        foreach ($definitions as $name => $runner)
        {
            if (is_string($name))
            {
                if (is_string($runner) && is_subclass_of($runner, Command::class))
                {
                    $this->add($name, function (OutputHelper $helper) use ($runner, $name)
                    {
                        $instance = new $runner($name);
                        return $instance($helper);
                    });
                } elseif ($runner instanceof \Closure || $runner instanceof Command)
                {
                    $this->add($name, $runner);
                    continue;
                }
            }

            throw new \InvalidArgumentException("Invalid definition {$name}");
        }

        return $this;
    }

    public function setFallbackDefinition(callable $runner): static
    {
        $this->definitions->set(self::FALLBACK_NAME, $runner);
        return $this;
    }

    public function getDefinitions(): Map
    {
        return $this->definitions;
    }
}
