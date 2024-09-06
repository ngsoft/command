<?php

declare(strict_types=1);

namespace NGSOFT\Command;

use NGSOFT\DataStructure\Map;
use NGSOFT\Text;

class Help extends Command implements \Stringable
{
    protected Map $definitions;

    protected ?Command $command  = null;

    protected ?string $generated = null;

    public function __construct()
    {
        $this->definitions = new Map();
        parent::__construct(
            'help',
            'Display help for the given command'
        );
    }

    public function __toString()
    {
        return $this->generated ?? '';
    }

    public static function getHelpFor(Command $command): \Stringable
    {
        $i            = new self();
        $i->generated = $i->generateCommandHelp($command);
        return $i;
    }

    public static function addHelpHandler(Command $command): void
    {
        $command->addOption(
            'help',
            ['h', 'help'],
            CommandOption::ValueOptional,
            ValueType::Boolean,
            false,
            'Display help for the given command'
        )->addHandler('help', fn (OutputHelper $helper, array $args, Command $command) => (new self())->handleCommandHelp($helper, $args, $command));
    }

    public function getDefinitions(): Map
    {
        return $this->definitions;
    }

    public function setDefinitions(Map $definitions): Help
    {
        $this->definitions = $definitions;
        return $this;
    }

    public function execute(OutputHelper $helper, array $args): int
    {
        if (empty($args['command']) && $command = $this->command)
        {
            $this->generated = $this->generateCommandHelp($command);
        } elseif (empty($args['command']) || $args['command'] === $this->name)
        {
            $commands        = [];

            foreach ($this->definitions as $cmd)
            {
                if ($cmd instanceof Command)
                {
                    $commands[$cmd->getName()] = $cmd;
                }
            }

            $this->generated = $this->generateCommandList(...$commands);
        } elseif (($command = $this->definitions->get($args['command'])) && $command instanceof Command)
        {
            $this->generated = $this->generateCommandHelp($command);
        }

        if (empty($this->generated))
        {
            return ExitCode::COMMAND_NOT_FOUND;
        }

        $helper->out($this->generated);
        return ExitCode::COMMAND_SUCCESS;
    }

    protected function configure(): void
    {
        $this->addOption(
            'help',
            ['h', 'help'],
            CommandOption::ValueOptional,
            ValueType::Boolean,
            false,
            'Display help for the given command'
        )->addArgument(
            'command',
            CommandArgument::Optional,
            ValueType::String,
            'help'
        );
    }

    protected function generateCommandList(Command ...$command): string
    {
        $sections   = [
            'Usage'              => ["  command [options] [arguments]\n"],
            'Options'            => $this->getOptionsLines($this->getOptions()),
            'Available commands' => [],
        ];

        $pad        = 0;
        $namespaces = [];

        foreach ($command as $cmd)
        {
            $name                   = $cmd->getName();
            $description            = $cmd->getDescription() ?: 'No description given for this command';
            $ns                     = explode(':', $name)[0];

            if ($name === $ns)
            {
                $ns = '';
            }

            $namespaces[$ns] ??= [];
            $namespaces[$ns][$name] = [$name, $description];

            $len                    = mb_strlen($name) + 1;

            if ($pad < $len)
            {
                $pad = $len;
            }
        }
        ksort($namespaces);

        if ( ! count($command))
        {
            unset($sections['Available commands']);
        }

        foreach ($namespaces as $ns => $items)
        {
            if ($ns)
            {
                $sections['Available commands'][] = " <yellow>{$ns}</yellow>\n";
            }
            ksort($items);

            foreach ($items as list($prefix, $body))
            {
                $sections['Available commands'][] = Text::of($prefix)
                    ->padEnd($pad)
                    ->prepend('  <green>')
                    ->concat('</>', "  {$body}\n")->toString()
                ;
            }
        }

        return $this->renderSections($sections);
    }

    protected function renderSections(array $sections): string
    {
        $result = Text::of('');

        foreach ($sections as $section => $lines)
        {
            if (empty($lines))
            {
                continue;
            }
            $result = $result->concat(
                "<yellow>{$section}:</yellow>\n",
                ...$lines
            )->concat("\n");
        }
        return $result->concat("\n")->toString();
    }

    protected function getOptionsLines(Map $options): array
    {
        $result = [];
        $pad    = 0;

        $lines  = [];

        /**
         * @var string        $opt
         * @var string        $description
         * @var mixed         $defaultValue
         * @var ValueType     $valueType
         * @var CommandOption $type
         * @var array         $flags
         */
        foreach ($options as list($description, $defaultValue, $valueType, $type, $flags))
        {
            $prefix      = '  ';

            foreach ($flags as list($str, $short))
            {
                $token = $short ? '-' : '--';
                $prefix .= sprintf('%s, ', $token . $str);
            }
            $prefix      = rtrim($prefix, ', ');

            $len         = mb_strlen($prefix) + 1;

            if ($len > $pad)
            {
                $pad = $len;
            }

            $description = $description ?: 'No description given for this option';
            $body        = " {$description}";
            $suffix      = '';

            if (
                CommandOption::ValueOptional === $type
                && null               !== $defaultValue
                && ValueType::Boolean !== $valueType
                && ''                 !== $defaultValue
                && ! ($defaultValue instanceof \Closure)
            ) {
                $suffix = sprintf(' [default: %s]', json_encode($defaultValue));
            }

            $lines[]     = [$prefix, $body, $suffix];
        }

        foreach ($lines as list($prefix, $body, $suffix))
        {
            $text     = Text::of($prefix)
                ->padEnd($pad)->prepend('<green>')->concat('</>')
                ->concat($body)
            ;

            if ( ! empty($suffix))
            {
                $text = $text->concat(
                    '<yellow>',
                    $suffix,
                    '</>'
                );
            }
            $result[] = $text->concat("\n")->toString();
        }
        return $result;
    }

    protected function generateCommandHelp(Command $command): string
    {
        $name        = $command->getName();

        $options     = $command->getOptions();
        $arguments   = $command->getArguments();
        $description = $command->getDescription() ?: 'No description given for this command';
        $usage       = "{$name}";

        if (count($options))
        {
            $usage .= ' [options]';
        }

        $required    = $optional = [];

        /** @var CommandArgument $type */
        foreach ($arguments as $name => list(, , , $type))
        {
            switch ($type)
            {
                case CommandArgument::Optional:
                    $optional[] = "[{$name}]";
                    break;
                case CommandArgument::Array:
                    $required[] = "...{$name}";
                    break;
                default:
                    $required[] = "{$name}";
            }
        }

        if (count($optional))
        {
            $usage .= sprintf(' %s', implode(' ', $optional));
        }

        foreach ($required as $name)
        {
            $usage .= " {$name}";
        }

        $sections    = [

            'Usage'       => ["  {$usage}\n"],
            'Description' => ["  {$description}\n"],
        ];

        if (count($options))
        {
            $sections['Options'] = $this->getOptionsLines($options);
        }

        return $this->renderSections($sections);
    }

    private function handleCommandHelp(OutputHelper $helper, array $args, Command $command): int
    {
        if ($args['help'] ?? null)
        {
            $this->command = $command;
            $this->execute($helper, []);
            return self::COMMAND_SUCCESS;
        }
        return -1;
    }
}
