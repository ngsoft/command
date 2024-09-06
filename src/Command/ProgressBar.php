<?php

declare(strict_types=1);

namespace NGSOFT\Command;

use NGSOFT\IO;
use NGSOFT\IO\CursorPosition;
use NGSOFT\IO\CustomTag;
use NGSOFT\IO\FormatterInterface;
use NGSOFT\IO\Style;
use NGSOFT\IO\TagFormatter;
use NGSOFT\IO\Terminal;
use NGSOFT\Text;
use NGSOFT\Tools;

class ProgressBar implements \Stringable
{
    public const DEFAULT_TEMPLATE        = "<cl>\r<progress:label:start> <progress:bar> <progress:indicator> <progress:label:end>";

    public const PART_COMPLETE_FULL      = 0;
    public const PART_COMPLETE_HALF      = 1;
    public const PART_REMAINING_HALF     = 2;
    public const PART_REMAINING_FULL     = 3;

    protected const TAG_MAP              = [
        'progress:label:start' => 'getLabelStart',
        'progress:label:end'   => 'getLabelEnd',
        'progress:bar'         => 'getProgressBar',
        'progress:indicator'   => 'getIndicator',
        'progress:time'        => 'getElapsedTime',
        'progress:percent'     => 'getPercentage',
    ];

    protected static int $counter        = 0;

    protected string $template           = self::DEFAULT_TEMPLATE;
    protected int $current               = 0;
    protected int $total                 = 100;
    protected bool $complete             = false;

    protected ?CursorPosition $position  = null;

    protected FormatterInterface $formatter;

    protected string $progressLabelStart = '';
    protected string $progressLabelEnd   = '';
    protected ?string $progressBar       = null;
    protected string $progressIndicator  = '<purple-500><progress:percent>%</> • <sky-500><progress:time></>';
    protected ?float $startTime          = null;

    protected int $barLength             = 20;

    protected array $parts               = [
        '━',
        '╸',
        '╺',
        '━',
    ];

    protected array $partsNoColor        = [
        '=',
        ' ',
        ' ',
        '-',
    ];

    protected Style $completedStyle;
    protected Style $remainingStyle;
    protected Style $finishedStyle;

    /**
     * @var array<int,callable[]>
     */
    protected array $handlers            = [[], [], []];

    public function __construct(protected ?IO $io = null)
    {
        $this->io ??= IO::create();

        $this->completedStyle = Style::make(
            IO\RgbColor::createFromRgb(236, 72, 153)
        );

        $this->remainingStyle = Style::make(
            IO\RgbColor::createFromRgb(58, 58, 58)
        );

        $this->finishedStyle  = Style::make(
            IO\RgbColor::createFromRgb(16, 185, 129)
        );

        $this->addHandlers();
    }

    public function __clone(): void
    {
        $this->addHandlers();
    }

    public function __toString(): string
    {
        return $this->template;
    }

    public function update(): void
    {
        if ( ! isset($this->startTime))
        {
            $this->startTime = Tools::getExecutionTime();
            ProgressStatus::Start->runHandlers($this->handlers, $this);
            ++self::$counter;

            $this->io->print(
                $this->io->getCursor()->hide()
            );
        }

        if ( ! $this->position)
        {
            if ($this->io->getCursor()->y >= Terminal::getHeight() - 1)
            {
                $this->io->print(Text::of("\n")->repeat(5));
                $this->position = new CursorPosition(1, Terminal::getHeight() - 5);
            } else
            {
                $this->io->print(
                    $this->io->getCursor()->moveStartDown()
                );
                $this->position = $this->io->getCursor()->getCursorPosition();
            }
        }

        $this->io->print(
            $this->io->getCursor()->setPosition($this->position)
        );

        if ($this->complete && ! $this->progressBar)
        {
            --self::$counter;

            if ( ! self::$counter)
            {
                $this->io->print(
                    $this->io->getCursor()->show()
                );
                $add = $this->io->getCursor()->show() . "\n";
            }
        }

        $this->io->print(
            $this->formatter->format(
                $this
            ),
            $add ?? ''
        );
    }

    public function getBarLength(): int
    {
        return $this->barLength;
    }

    public function setBarLength(int $barLength): ProgressBar
    {
        $this->barLength = max(10, $barLength);
        return $this;
    }

    public function getElapsedTime(): string
    {
        $sec     = intval(round(Tools::getExecutionTime() - $this->startTime));
        $hours   = floor($sec / 3600);
        $minutes = floor(($sec % 3600) / 60);
        $sec     = $sec % 60;

        return ltrim(
            ltrim(
                sprintf('%02d:%02d:%02d', $hours, $minutes, $sec),
                '0'
            ),
            ':'
        );
    }

    public function getPercentage(): string
    {
        return substr(sprintf('  %d', $this->getPercent()), -3);
    }

    public function getIndicator(): string
    {
        return $this->progressIndicator;
    }

    public function getProgressBar(CustomTag $tag): string
    {
        if ( ! $this->progressBar)
        {
            $parts             = $this->partsNoColor;

            if ($tag->supportsColor())
            {
                $parts = $this->parts;
            }

            $finished          = $this->complete;

            if ($finished)
            {
                $progress = Text::of(
                    $this->finishedStyle->format($parts[self::PART_COMPLETE_FULL])
                )->repeat($this->barLength);
            } else
            {
                $complete  = (int) floor(intval($this->barLength * 2 * $this->getCurrent() / $this->getTotal()) / 2);
                $half      = 1 === $complete % 2;
                $remaining = $this->barLength - $complete - 1;
                $progress  = Text::of(
                    $this->completedStyle->format(
                        $parts[self::PART_COMPLETE_FULL]
                    )
                )->repeat($complete);

                if ($half)
                {
                    $progress = $progress->concat(
                        $this->completedStyle->format(
                            $parts[self::PART_COMPLETE_HALF]
                        )
                    );
                } else
                {
                    $progress = $progress->concat(
                        $this->remainingStyle->format(
                            $parts[self::PART_REMAINING_HALF]
                        )
                    );
                }

                $progress  = $progress->concat(
                    Text::of(
                        $this->remainingStyle->format(
                            $parts[self::PART_REMAINING_FULL]
                        )
                    )->repeat($remaining)
                );
            }
            $this->progressBar = str_val($progress);
        }

        return $this->progressBar;
    }

    public function getLabelEnd(): string
    {
        return $this->progressLabelEnd;
    }

    public function getLabelStart(): string
    {
        return $this->progressLabelStart;
    }

    public function getFormatter(): FormatterInterface
    {
        return $this->formatter;
    }

    public function getParts(): array
    {
        return $this->parts;
    }

    public function setParts(array $parts): ProgressBar
    {
        if (4 !== count(array_filter($parts, 'is_string')))
        {
            throw new \InvalidArgumentException(
                'Invalid parts provided for progressBar.'
            );
        }

        $this->parts = $parts;
        return $this;
    }

    public function getTotal(): int
    {
        return $this->total;
    }

    public function getCurrent(): int
    {
        return $this->current;
    }

    public function setTotal(int $total): ProgressBar
    {
        $this->progressBar = null;
        $this->total       = max(1, $total);
        $this->current     = 0;
        return $this;
    }

    public function setCurrent(int $current): ProgressBar
    {
        if ( ! $this->complete)
        {
            $this->current     = $current = min($current, $this->total);
            $this->progressBar = null;

            if ($current === $this->total)
            {
                return $this->setComplete(true);
            }
            ProgressStatus::Progress->runHandlers($this->handlers, $this);
            $this->update();
        }

        return $this;
    }

    public function getPercent(): int
    {
        return min(intval(($this->current / $this->total) * 100), 100);
    }

    public function isComplete(): bool
    {
        return $this->complete;
    }

    public function setComplete(bool $complete): ProgressBar
    {
        if ( ! $this->complete && $complete)
        {
            $this->complete    = true;
            $this->current     = $this->total;
            $this->progressBar = null;
            ProgressStatus::Complete->runHandlers($this->handlers, $this);
            $this->update();
        }

        return $this;
    }

    public function setTemplate(string|\Stringable $template): ProgressBar
    {
        $this->template = str_val($template);
        return $this;
    }

    public function setFormatter(FormatterInterface $formatter): ProgressBar
    {
        $this->formatter = $formatter;
        return $this;
    }

    public function setLabelEnd(string $progressLabelEnd): ProgressBar
    {
        $this->progressLabelEnd = $progressLabelEnd;
        return $this;
    }

    public function setLabelStart(string $progressLabelStart): ProgressBar
    {
        $this->progressLabelStart = $progressLabelStart;
        return $this;
    }

    public function addHandler(ProgressStatus $status, callable $handler): ProgressBar
    {
        if ( ! in_array($handler, $this->handlers[$status->value], true))
        {
            $this->handlers[$status->value][] = $handler;
        }
        return $this;
    }

    protected function addHandlers(): void
    {
        $this->formatter = $f = new TagFormatter(
            $this->io->getStyleMap()
        );

        foreach (self::TAG_MAP as $tag => $method)
        {
            $f->addCustomTag(CustomTag::createNew($tag, [$this, $method]));
        }

        $f->addCustomTag(
            CustomTag::createNew(
                'cl',
                function (CustomTag $tag): string
                {
                    if ($tag->supportsColor())
                    {
                        return IO\Ansi::CLEAR_LINE;
                    }

                    return Text::pad(
                        Terminal::getWidth() - 1,
                    )->prepend("\r")->concat("\r")->toString();
                }
            )
        );
    }
}
