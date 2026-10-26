<?php

namespace Phlisg\WideEvents\Commands\Table;

use Laravel\Prompts\DataTablePrompt;
use Laravel\Prompts\Themes\Default\DataTableRenderer;

class EventTableRenderer extends DataTableRenderer
{
    /**
     * Colors the cells after cutting them to width, so the widths are measured on the plain text.
     * The highlighted row stays plain: inverse turns the color into a background only behind the text.
     *
     * @param  array<int|string, array<int, string>>  $filtered
     * @param  array<int|string, array<int, string>>  $visible
     * @param  array<int, int>  $widths
     * @return array<int, string>
     */
    protected function renderDataRows(DataTablePrompt $prompt, array $filtered, array $visible, array $widths, int $numCols, int $innerWidth, bool $strikethrough = false): array
    {
        if ($prompt instanceof EventTable && ! $strikethrough) {
            $highlighted = $prompt->state === 'search' ? null : (array_keys($filtered)[$prompt->highlighted] ?? null);

            foreach ($visible as $key => $row) {
                if ($key !== $highlighted && ($color = $prompt->colors[$key] ?? null)) {
                    $visible[$key] = array_map(fn ($cell, $width) => implode(PHP_EOL, array_map(
                        fn ($line) => $this->{$color}($this->truncate($line, $width)),
                        explode(PHP_EOL, $cell),
                    )), $row, $widths);
                }
            }
        }

        return parent::renderDataRows($prompt, $filtered, $visible, $widths, $numCols, $innerWidth, $strikethrough);
    }

    // The parent truncates again; a colored cell already fits once its escape codes are left out.
    protected function truncate(string $string, int $width): string
    {
        return mb_strwidth($this->stripEscapeSequences($string)) <= $width ? $string : parent::truncate($string, $width);
    }
}
