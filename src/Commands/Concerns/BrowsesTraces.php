<?php

namespace MarvelousDigital\WideEvents\Commands\Concerns;

use function Laravel\Prompts\select;

/**
 * Picks an event from a trace and shows its fields, for a command using ReadsEvents.
 */
trait BrowsesTraces
{
    /**
     * Lists the trace as the jot:query tree, the cursor on the span given, and returns the event picked.
     *
     * @param  list<string>  $files
     * @return array<string, mixed>
     */
    protected function pick(array $files, string $traceId, ?string $span = null): array
    {
        $trace = $this->find($files, $traceId)->where('trace_id', $traceId)->sortBy('timestamp')->values();
        // Prompts measures labels by length and styles them itself, so the console tags go.
        $lines = array_map(fn ($line) => (string) preg_replace('#</?(comment|info|error)>#', '', $line), $this->tree($trace, $span));

        $picked = select("Trace {$traceId}, {$trace->count()} event(s)", $lines, default: $span, scroll: min(10, count($lines)));

        return $trace->firstWhere('span_id', (string) $picked) ?? $trace->first();
    }

    /**
     * Prints every field of the event, then asks what next, picking again from the trace until another choice.
     *
     * @param  list<string>  $files
     * @param  array<string, mixed>  $event
     * @param  array<string, string>  $choices  the 'trace' key shows the trace
     */
    protected function inspect(array $files, array $event, array $choices): string
    {
        do {
            $this->fields($event);
            $next = (string) select('Next', $choices);

            if ($next === 'trace') {
                $event = $this->pick($files, (string) $event['trace_id'], $event['span_id']);
            }
        } while ($next === 'trace');

        return $next;
    }
}
