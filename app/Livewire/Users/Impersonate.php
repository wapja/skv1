<?php

namespace App\Livewire\Users;

use App\Exceptions\Impersonation\CannotImpersonateSuperAdmin;
use App\Exceptions\Impersonation\ImpersonationDepthExceeded;
use App\Exceptions\Impersonation\ImpersonationNotPermitted;
use App\Http\Requests\StartImpersonationRequest;
use App\Models\User;
use App\Services\ImpersonationGuard;
use Livewire\Attributes\On;
use Livewire\Attributes\Validate;
use Livewire\Component;

class Impersonate extends Component
{
    public bool $open = false;

    public ?int $targetUserId = null;

    #[Validate('required|string|min:1|max:500')]
    public string $reason = '';

    #[On('open-impersonate')]
    public function openFor(int $userId): void
    {
        $this->targetUserId = $userId;
        $this->reason = '';
        $this->open = true;
    }

    protected function rules(): array
    {
        return (new StartImpersonationRequest)->rules();
    }

    public function start(ImpersonationGuard $guard): mixed
    {
        $this->validate();

        $target = User::withoutTenantScope()->findOrFail($this->targetUserId);
        $actor = auth()->user();

        try {
            $guard->start($actor, $target, $this->reason);
        } catch (CannotImpersonateSuperAdmin) {
            $this->addError('reason', __('Super-admin accounts kunnen niet worden geïmpersoneerd.'));

            return null;
        } catch (ImpersonationDepthExceeded) {
            $this->addError('reason', __('Je impersoneert al een gebruiker.'));

            return null;
        } catch (ImpersonationNotPermitted) {
            $this->addError('reason', __('Je mag deze gebruiker niet impersoneren.'));

            return null;
        }

        return redirect()->route('dashboard');
    }

    public function render()
    {
        return view('livewire.users.impersonate');
    }
}
