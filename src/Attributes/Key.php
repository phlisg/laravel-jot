<?php

namespace Phlisg\WideEvents\Attributes;

use Attribute;

/**
 * Writes a section property under this exact key, with no section prefix.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Key
{
    public function __construct(public string $name) {}
}
