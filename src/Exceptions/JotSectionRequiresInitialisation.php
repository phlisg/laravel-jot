<?php

namespace Phlisg\Jot\Exceptions;

use LogicException;

class JotSectionRequiresInitialisation extends LogicException
{
    /**
     * @param  class-string<\Phlisg\Jot\Section>  $section
     */
    public static function for(string $section): self
    {
        return new self(sprintf(
            'Section %s has required constructor arguments, so jot(%s::class) cannot create it. Add it first with jot()->add(new %s(...)).',
            $section,
            class_basename($section),
            class_basename($section),
        ));
    }
}
