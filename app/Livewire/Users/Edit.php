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

    public string $first_name = '';

    public string $middle_name = '';

    public string $last_name = '';

    public string $internal_id = '';

    public string $phone = '';

    public string $address = '';

    public string $start_date = '';

    public string $end_date = '';

    public string $email = '';

    public string $status = '';

    public string $locale = '';

    public function mount(User $user): void
    {
        $this->authorize('update', $user);

        $this->user = $user;
        $this->first_name = $user->first_name ?? '';
        $this->middle_name = $user->middle_name ?? '';
        $this->last_name = $user->last_name ?? '';
        $this->internal_id = $user->internal_id ?? '';
        $this->phone = $user->phone ?? '';
        $this->address = $user->address ?? '';
        $this->start_date = $user->start_date?->toDateString() ?? '';
        $this->end_date = $user->end_date?->toDateString() ?? '';
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
