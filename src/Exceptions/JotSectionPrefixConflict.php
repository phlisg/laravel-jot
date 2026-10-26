<?php

namespace Phlisg\WideEvents\Exceptions;

use LogicException;
use Phlisg\WideEvents\Section;

class JotSectionPrefixConflict extends LogicException
{
    /**
     * @param  class-string<Section>  $section
     */
    public static function for(string $section, Section $found): self
    {
        return new self(sprintf(
            'Section %s shares the prefix "%s" with %s, which holds that slot in the event. Give one of them its own $prefix.',
            $section,
            $section::prefix(),
            $found::class,
        ));
    }
}
