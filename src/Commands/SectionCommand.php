<?php

namespace Phlisg\WideEvents\Commands;

use Illuminate\Console\GeneratorCommand;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'jot:section')]
class SectionCommand extends GeneratorCommand
{
    protected $name = 'jot:section';

    protected $description = 'Create a new Jot section class in app/Jot';

    protected $type = 'Section';

    protected function getStub(): string
    {
        return __DIR__.'/../../stubs/section.stub';
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\Jot';
    }
}
