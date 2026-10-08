<?php

use Phlisg\Jot\Jot;
use Phlisg\Jot\Section;

if (! function_exists('jot')) {
    function jot(Section|string|null $section = null): Jot|Section
    {
        $jot = app(Jot::class);

        if ($section) {
            if (! is_subclass_of($section, Section::class)) {
                throw new InvalidArgumentException('Section must be a subclass of '.Section::class);
            }

            return $jot->getSection($section);
        }

        return $jot;
    }
}
