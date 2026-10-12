<?php

namespace MarvelousDigital\WideEvents\Sections;

use Illuminate\Console\Scheduling\Event;
use MarvelousDigital\WideEvents\Section;

final class ScheduleSection extends Section
{
    public readonly ?string $task;

    public readonly string $expression;

    public ?int $exitCode = null;

    public function __construct(Event $task)
    {
        $this->task = $task->description ?? $task->command;
        $this->expression = $task->expression;
    }
}
