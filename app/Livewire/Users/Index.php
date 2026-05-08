<?php

namespace App\Livewire\Users;

use App\Models\User;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

class Index extends Component
{
    #[Url(as: 'status')]
    public string $statusFilter = '';

    public function delete(int $userId): void
    {
        $user = User::findOrFail($userId);
        $this->authorize('delete', $user);
        $user->delete();
        session()->flash('status', __('Gebruiker verwijderd.'));
    }

    public function users()
    {
        $query = User::query()->orderBy('last_name')->orderBy('first_name');
        if ($this->statusFilter !== '') {
            $query->where('status', $this->statusFilter);
        }

        return $query->get();
    }

    #[Layout('components.layouts.app')]
    #[Title('Gebruikers')]
    public function render()
    {
        $this->authorize('viewAny', User::class);

        return view('livewire.users.index', [
            'users' => $this->users(),
        ]);
    }
}
