<?php

declare(strict_types=1);

namespace NGSOFT\Command;

use NGSOFT\IO;
use NGSOFT\IO\BackgroundColor;
use NGSOFT\IO\CustomTag;
use NGSOFT\IO\ErrorOutput;
use NGSOFT\IO\NullFormatter;
use NGSOFT\IO\Output;
use NGSOFT\IO\Style;
use NGSOFT\IO\TagFormatter;
use NGSOFT\IO\Terminal;
use NGSOFT\Text;

use function NGSOFT\Tools\split_string;

class OutputHelper
{
    protected IO $io;
    protected TagFormatter $formatter;

    protected Output $out;
    protected Output $err;

    public function __construct()
    {
        // new instance, not global
        $this->io        = new IO();
        // using NullFormatter to output raw string using provided out() and err() methods
        $this->out       = new Output($null = new NullFormatter());
        $this->err       = new ErrorOutput($null);
        // creating a new instance of TagFormatter with a new StyleMap
        // Custom tags from this instance will only be bound to this instance of TagFormatter
        // As custom tags can only be added once to a tag formatter
        $this->formatter = new TagFormatter();
        $this->registerCustomTags($this->formatter);
        // Binding this custom TagFormatter to this instance of IO to use custom tags
        $this->io->setFormatter($this->formatter);
    }

    public function __debugInfo(): array
    {
        return [];
    }

    public function out(string|\Stringable ...$messages): static
    {
        foreach ($messages as $raw)
        {
            $msg = $this->formatter->format($raw);
            $this->out->write($msg);
        }
        return $this;
    }

    public function err(string|\Stringable ...$messages): static
    {
        foreach ($messages as $raw)
        {
            $msg = $this->formatter->format($raw);
            $this->err->write($msg);
        }
        return $this;
    }

    public function getIo(): IO
    {
        return $this->io;
    }

    public function setIo(IO $io): OutputHelper
    {
        $this->io = $io;
        return $this;
    }

    public function registerCustomTags(TagFormatter $tagFormatter): OutputHelper
    {
        static $tags = ['section', 'title', 'text', 'block'];

        foreach ($tags as $tag)
        {
            $tagFormatter->addCustomTag(
                CustomTag::createNew(
                    $tag,
                    // this is how to use protected methods as handlers
                    fn (CustomTag $tag, string $contents): string => $this->customTagHandler($tag, $contents),
                    true
                )
            );
        }

        return $this;
    }

    public function mergeStyles(string|Style ...$styles): Style
    {
        $result = new Style();

        $map    = $this->io->getStyleMap();

        /* @var IO\CustomColorInterface $obj */
        foreach ($styles as $style)
        {
            if (is_string($style))
            {
                foreach (preg_split('#\h+#', $style) as $input)
                {
                    if (empty($input))
                    {
                        continue;
                    }

                    foreach ($map->getStyle($input) ?? [] as $obj => $_)
                    {
                        $result->addStyle($obj);
                    }
                }

                continue;
            }

            foreach ($style as $obj => $_)
            {
                $result->addStyle($obj);
            }
        }

        return $result;
    }

    public function success(array|string $message): string
    {
        return $this->text(
            $this->block(
                $message,
                'black bg:green',
                'OK',
                ' ',
                max(64, Terminal::getWidth() - 1)
            )
        );
    }

    public function error(array|string $message): string
    {
        return $this->text(
            $this->block(
                $message,
                'bg:red black',
                'ERROR',
                ' ',
                max(64, Terminal::getWidth() - 2)
            )
        );
    }

    public function warning(array|string $message): string
    {
        return
            $this->text(
                $this->block(
                    $message,
                    'bg:warning',
                    'WARNING',
                    ' ',
                    max(64, Terminal::getWidth() - 2)
                )
            );
    }

    public function warn(array|string $message): string
    {
        return $this->block(
            $message,
            'comment',
            'WARN',
            ' ',
            max(64, Terminal::getWidth() - 2),
            padding: 2,
            center: false
        );
    }

    public function info(array|string $message): string
    {
        return $this->block(
            $message,
            'info',
            'INFO',
            ' ',
            max(64, Terminal::getWidth() - 2),
            padding: 2,
            center: false
        );
    }

    public function alert(array|string $message): string
    {
        return $this->block(
            $message,
            'alert',
            'ALERT',
            ' ',
            max(64, Terminal::getWidth() - 2),
            padding: 2,
            center: false
        );
    }

    public function notice(array|string $message): string
    {
        return $this->block(
            $message,
            'notice',
            'NOTICE',
            ' ',
            max(64, Terminal::getWidth() - 2),
            padding: 2,
            center: false
        );
    }

    public function block(mixed $messages, null|string|Style $style = null, mixed $type = null, mixed $prefix = '', int $length = 0, int $maxLength = 64, int $padding = 4, bool $center = true): string
    {
        if ( ! is_array($messages))
        {
            $messages = [$messages];
        }

        $prefix ??= '';
        $prefix    = strip_tags(str_val($prefix));

        $messages  = array_map(fn ($m) => strip_tags(str_val($m) . "\n"), $messages);

        $style  ??= Style::make(BackgroundColor::Black);

        if (is_string($style))
        {
            $style = $this->mergeStyles($style);
        }

        $message   = array_shift($messages);
        $strList   = Text::of($message)
            ->concat(...$messages)
            ->expandTabs()
            ->trimEnd("\n")
            ->split("#(\r\n|\n)#")
        ;

        if ($maxLength < $length)
        {
            $maxLength = $length;
        }

        $padType   = 0;

        if (isset($type))
        {
            $type    = sprintf('[%s] ', str_val($type));
            $padType = mb_strlen($type);
        }

        $minSize   = max(0, $length);
        $maxSize   = $maxLength - ($padding * 2);

        $lines     = [];

        foreach ($strList as $str)
        {
            if ($prefix)
            {
                $str = $str->prepend($prefix);
            }

            if ($minSize < $str->getLength())
            {
                $minSize = min($str->getLength(), $maxSize);
            }

            if ($str->getLength() > $maxSize)
            {
                $lines = array_merge($lines, split_string($str, $minSize));
                continue;
            }
            $lines[] = $str;
        }

        $lines     = ['', ...$lines, ''];

        $minLength = min($maxLength, $minSize + ($padding * 2));

        // render
        $block     = Text::of("\n");

        foreach ($lines as $i => $line)
        {
            $text  = Text::of($line);

            if ($type)
            {
                if (1 === $i)
                {
                    $text = $text->prepend($type);
                } elseif (in_range($i, 2, count($lines) - 2))
                {
                    $text = $text->prepend(
                        Text::of(' ')->repeat($padType)
                    );
                }
            }

            if ($center)
            {
                $text = $text->padAll($minLength);
            } else
            {
                $text = $text
                    ->padStart(length($text) + $padding)
                    ->padEnd($minLength)
                ;
            }
            $block = $block->concat(
                $style->format($text),
                "\n"
            );
        }

        return $block->toString();
    }

    public function title(mixed $contents, null|string|Style $style = null, string $sep = '=', int $length = -1): string
    {
        if (empty($contents = strip_tags(str_val($contents))))
        {
            return '';
        }

        $style ??= $this->io->getStyleMap()->getStyle('comment');

        if (is_string($style))
        {
            $style = $this->mergeStyles($style);
        }

        $min    = $this->getContentLength($contents);

        if ($length < 0)
        {
            $max    = Terminal::getWidth() - 1;
            $length = min($min, $max);
        }

        $length = max($min, $length);
        $pad    = '';

        if ($length > 0 && mb_strlen($sep) > 0)
        {
            $pad = $style->format(Text::pad($length, $sep));
        }

        $block  = Text::of("\n\n")->concat(
            $style->format($contents),
            "\n",
            $pad,
            "\n"
        );

        return $block->toString();
    }

    public function section(mixed $contents, null|string|Style $style = null, string $sep = '-', int $length = -1): string
    {
        return $this->title($contents, $style, $sep, $length);
    }

    public function listing(iterable $elements, null|string|Style $style = null, string $pill = '•'): string
    {
        if (is_string($style))
        {
            $style = $this->mergeStyles($style);
        }
        $block = Text::of("\n");

        foreach ($elements as $line)
        {
            $line  = trim(strip_tags(str_val($line)));

            if (empty($line))
            {
                continue;
            }

            $line  = "{$pill} {$line}";

            if ($style)
            {
                $line = $style->format($line);
            }

            $block = $block->concat(' ', $line, "\n");
        }

        return $block->concat("\n")->toString();
    }

    public function text(mixed $messages, int $padding = 1, null|string|Style $style = null): string
    {
        $padding = max(0, $padding);
        $pad     = Text::of(' ')->repeat($padding);

        if (is_string($style))
        {
            $style = $this->mergeStyles($style);
        }

        if ( ! is_array($messages))
        {
            $messages = [$messages];
        }
        $result  = new Text();

        foreach ($messages as $message)
        {
            $lines = preg_split("#(\r\n|\n)#", $message);

            foreach ($lines as $line)
            {
                if ($style)
                {
                    $line = $style->format($line);
                }
                $result = $result->concat($pad, $line, "\n");
            }
        }
        return $result->toString();
    }

    protected function customTagHandler(CustomTag $tag, string $contents): string
    {
        $args = [];

        if ($style = $tag->getStyle($this->io->getStyleMap()))
        {
            $args['style'] = $style;
        }

        switch ($tag->getName())
        {
            case 'section':
            case 'title':
                if ( ! blank($attr = $tag->getAttribute(['sep', 'separator'])))
                {
                    $args['sep'] = $attr;
                }

                if (is_int($attr = $tag->getAttribute(['length', 'len', 'width'])))
                {
                    $args['length'] = max(0, $attr);
                }

                if ('section' === $tag->getName())
                {
                    return $this->section($contents, $style, ...$args);
                }
                return $this->title($contents, ...$args);

            case 'text':
                if (is_int($padding = $tag->getAttribute('padding', 1)))
                {
                    $args['padding'] = $padding;
                }
                return $this->text($contents, ...$args);
            case 'block':

                if (is_int($attr = $tag->getAttribute(['length', 'width', 'len'])))
                {
                    $args['length'] = $attr;
                }

                if (is_int($attr = $tag->getAttribute(['maxlength', 'max'])))
                {
                    $args['maxLength'] = $attr;
                }

                if (is_int($attr = $tag->getAttribute(['padding', 'pad'])))
                {
                    $args['padding'] = $attr;
                }

                if ( ! blank($attr = $tag->getAttribute('type')) && ! is_bool($attr))
                {
                    $args['type'] = $attr;
                }

                if ( ! blank($attr = $tag->getAttribute('prefix')) && ! is_bool($attr))
                {
                    $args['prefix'] = $attr;
                }
                return $this->block($contents, ...$args);
        }
        return '';
    }

    protected function removeTags(mixed $contents): string
    {
        return strip_tags(str_val($contents));
    }

    protected function removeStyles(mixed $contents): string
    {
        $contents = str_val($contents);
        return preg_replace('#\x1b\[[^m]*m#i', '', $contents);
    }

    protected function getContentLength(mixed $contents): int
    {
        return mb_strlen($this->removeTags($this->removeStyles($contents)));
    }
}
