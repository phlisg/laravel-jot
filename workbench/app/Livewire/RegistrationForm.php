<?php

namespace Workbench\App\Livewire;

use Livewire\Component;

class RegistrationForm extends Component
{
    public string $email = '';

    public int $step = 1;

    public function nextStep(): void
    {
        $this->step++;
    }

    public function submit(): void
    {
        $this->validate(['email' => 'required|email']);
    }

    public function render(): string
    {
        return '<form wire:submit="submit">Step {{ $step }}</form>';
    }
}
