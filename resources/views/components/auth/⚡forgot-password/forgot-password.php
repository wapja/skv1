<?php

use Illuminate\Support\Facades\Password;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

new class extends Component
{
    #[Validate('required|email')]
    public string $email = '';

    public ?string $status = null;

    public function submit(): void
    {
        $this->validate();

        $status = Password::sendResetLink(['email' => $this->email]);

        // Don't leak whether the email exists — show success either way.
        $this->status = __(Password::RESET_LINK_SENT);
    }

    #[Layout('components.layouts.guest')]
    #[Title('Wachtwoord vergeten')]
    public function render()
    {
        return $this->view();
    }
};
