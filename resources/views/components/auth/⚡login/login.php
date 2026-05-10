<?php

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

new class extends Component
{
    #[Validate('required|email')]
    public string $email = '';

    #[Validate('required|string')]
    public string $password = '';

    public bool $remember = false;

    public function submit(): mixed
    {
        $this->validate();

        $tenant = tenant();

        $credentials = [
            'email' => $this->email,
            'password' => $this->password,
            'status' => 'active',
        ];

        if ($tenant) {
            $credentials['organisation_id'] = $tenant->id;
        }

        if (! Auth::attempt($credentials, $this->remember)) {
            $this->addError('email', __('Onjuiste inloggegevens.'));

            return null;
        }

        session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    #[Layout('components.layouts.guest')]
    #[Title('Inloggen')]
    public function render()
    {
        return $this->view();
    }
};
