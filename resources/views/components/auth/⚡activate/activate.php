<?php

use App\Exceptions\Invitation\InvitationAlreadyAccepted;
use App\Exceptions\Invitation\InvitationCancelled;
use App\Exceptions\Invitation\InvitationExpired;
use App\Models\Invitation;
use App\Models\User;
use App\Services\InvitationService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

new class extends Component
{
    public string $token = '';

    public string $email = '';

    #[Validate('required|string|min:8|confirmed')]
    public string $password = '';

    public string $password_confirmation = '';

    public function mount(string $token): void
    {
        $this->token = $token;

        $invitation = Invitation::where('token', $token)->first();
        if ($invitation) {
            $user = User::withoutTenantScope()->withTrashed()->find($invitation->user_id);
            $this->email = $user?->email ?? '';
        }
    }

    public function submit(InvitationService $service): mixed
    {
        $this->validate();

        try {
            $user = $service->accept($this->token, $this->password);
        } catch (InvitationExpired) {
            $this->addError('token', __('Deze uitnodiging is verlopen.'));

            return null;
        } catch (InvitationAlreadyAccepted) {
            $this->addError('token', __('Deze uitnodiging is al gebruikt.'));

            return null;
        } catch (InvitationCancelled) {
            $this->addError('token', __('Deze uitnodiging is ingetrokken.'));

            return null;
        }

        Auth::login($user);
        session()->regenerate();

        return redirect()->route('dashboard');
    }

    #[Layout('components.layouts.guest')]
    #[Title('Account activeren')]
    public function render()
    {
        return $this->view();
    }
};
