<?php

namespace App\Livewire\Invitations;

use App\Models\Organisation;
use App\Services\InvitationService;
use Livewire\Attributes\On;
use Livewire\Attributes\Validate;
use Livewire\Component;

class Send extends Component
{
    public bool $open = false;

    #[Validate('required|string|max:255')]
    public string $firstName = '';

    #[Validate('nullable|string|max:255')]
    public string $middleName = '';

    #[Validate('required|string|max:255')]
    public string $lastName = '';

    #[Validate('required|email')]
    public string $email = '';

    #[Validate('required|in:nl,en')]
    public string $locale = 'nl';

    public array $roles = [];

    public ?int $organisationId = null;

    #[On('open-invite-modal')]
    public function openModal(): void
    {
        $this->reset(['firstName', 'middleName', 'lastName', 'email', 'roles', 'organisationId']);
        $this->open = true;
    }

    /**
     * @return array<int,string> id => name
     */
    public function availableOrganisations(): array
    {
        if (tenant() !== null) {
            return [];
        }

        if (! auth()->user()?->isSuperAdmin()) {
            return [];
        }

        return Organisation::orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * @return array<string,string> internal role-name => translated label
     */
    public function availableRoles(): array
    {
        $base = [
            'organisation_admin' => __('Organisatie-admin'),
            'test1' => __('Test rol 1'),
            'test2' => __('Test rol 2'),
        ];

        if (auth()->user()?->isSuperAdmin()) {
            return ['super_admin' => __('Super-admin')] + $base;
        }

        return $base;
    }

    public function send(InvitationService $service): void
    {
        $user = auth()->user();
        abort_unless($user?->can('invitations.send'), 403);

        // Apex-flow is super-admin-only. A regular admin who somehow
        // authenticated on the apex host must not be able to invite
        // into an arbitrary organisation.
        if (! tenant() && ! $user->isSuperAdmin()) {
            abort(403);
        }

        $this->validate();

        $this->validate([
            'roles' => ['array'],
            'roles.*' => ['required', 'string', 'in:'.implode(',', array_keys($this->availableRoles()))],
        ]);

        if ($tenant = tenant()) {
            $organisationId = $tenant->id;
        } else {
            $this->validate([
                'organisationId' => ['required', 'integer', 'exists:organisations,id'],
            ]);
            $organisationId = (int) $this->organisationId;
        }

        $service->invite(
            firstName: $this->firstName,
            middleName: $this->middleName !== '' ? $this->middleName : null,
            lastName: $this->lastName,
            email: $this->email,
            locale: $this->locale,
            roles: $this->roles,
            invitedBy: $user,
            organisationId: $organisationId,
        );

        $this->reset(['firstName', 'middleName', 'lastName', 'email', 'roles', 'organisationId', 'open']);
        $this->dispatch('invitation-sent');
        session()->flash('status', __('Uitnodiging verzonden.'));
    }

    public function render()
    {
        return view('livewire.invitations.send');
    }
}
