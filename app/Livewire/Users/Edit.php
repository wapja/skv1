<?php

namespace App\Livewire\Users;

use App\Http\Requests\UpdateUserRequest;
use App\Models\User;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

class Edit extends Component
{
    public User $user;

    public string $name = '';

    public string $email = '';

    public string $status = '';

    public string $locale = '';

    public function mount(User $user): void
    {
        $this->authorize('update', $user);

        $this->user = $user;
        $this->name = $user->name;
        $this->email = $user->email;
        $this->status = $user->status;
        $this->locale = $user->locale;
    }

    protected function rules(): array
    {
        return (new UpdateUserRequest)->rules();
    }

    public function save(): mixed
    {
        $this->authorize('update', $this->user);

        $validated = $this->validate();

        $this->user->update($validated);

        session()->flash('status', __('Gebruiker opgeslagen.'));

        return redirect()->route('users.index');
    }

    #[Layout('components.layouts.app')]
    #[Title('Gebruiker bewerken')]
    public function render()
    {
        return view('livewire.users.edit');
    }
}
