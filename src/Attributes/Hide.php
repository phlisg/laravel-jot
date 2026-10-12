<?php

namespace MarvelousDigital\WideEvents\Attributes;

use Attribute;

/**
 * Keeps a public section property out of the event.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Hide {}
