<?php

namespace MarvelousDigital\WideEvents\Sections;

use MarvelousDigital\WideEvents\Section;

/**
 * Argument and option names only, never their values: values can carry secrets.
 */
final class ConsoleSection extends Section
{
    public ?array $arguments = null;

    public ?array $options = null;

    public ?int $exitCode = null;

    public function __construct(public readonly string $command) {}
}
