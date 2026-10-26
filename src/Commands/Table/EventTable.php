<?php

namespace Phlisg\WideEvents\Commands\Table;

use Laravel\Prompts\DataTablePrompt;
use Phlisg\WideEvents\Writer;
use Psr\Log\LogLevel;

/**
 * The jot:query table, its rows colored red or yellow by the level of their event.
 */
class EventTable extends DataTablePrompt
{
    /**
     * The color of each row by its key: red, yellow, or none when missing.
     *
     * @var array<int|string, string>
     */
    public array $colors = [];

    /**
     * Red for an error.type, a 5xx status or a failed job, yellow for a 4xx status or a non-zero exit code.
     *
     * @param  array<string, mixed>  $event
     */
    public static function color(array $event): ?string
    {
        return match (Writer::level($event)) {
            LogLevel::EMERGENCY, LogLevel::ALERT, LogLevel::CRITICAL, LogLevel::ERROR => 'red',
            LogLevel::WARNING => 'yellow',
            default => null,
        };
    }

    // Prompts looks renderers up by exact class, so a subclass names its own.
    protected function getRenderer(): callable
    {
        return new EventTableRenderer($this);
    }
}
