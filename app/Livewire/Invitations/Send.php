<?php

namespace App\Livewire\Invitations;

use App\Services\InvitationService;
use Livewire\Attributes\On;
use Livewire\Attributes\Validate;
use Livewire\Component;

class Send extends Component
{
    public bool $open = false;

    #[Validate('required|email')]
    public string $email = '';

    #[Validate('required|in:nl,en')]
    public string $locale = 'nl';

    #[Validate('array')]
    public array $roles = [];

    #[On('open-invite-modal')]
    public function openModal(): void
    {
        $this->reset(['email', 'roles']);
        $this->open = true;
    }

    public function send(InvitationService $service): void
    {
        abort_unless(auth()->user()?->can('invitations.send'), 403);

        $this->validate();

        $service->invite($this->email, $this->locale, $this->roles, auth()->user());

        $this->reset(['email', 'roles', 'open']);
        $this->dispatch('invitation-sent');
        session()->flash('status', __('Uitnodiging verzonden.'));
    }

    public function render()
    {
        return view('livewire.invitations.send');
    }
}
