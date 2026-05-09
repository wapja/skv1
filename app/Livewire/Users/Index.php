<?php

namespace App\Livewire\Users;

use App\Models\User;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Session;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

class Index extends Component
{
    #[Url(as: 'status')]
    public string $statusFilter = '';

    /** @var array<int,string> */
    #[Session]
    public array $selectedColumns = ['name', 'email', 'status'];

    public function delete(int $userId): void
    {
        $user = User::findOrFail($userId);
        $this->authorize('delete', $user);
        $user->delete();
        session()->flash('status', __('Gebruiker verwijderd.'));
    }

    /**
     * Map of column key => translated label. The `name` key is a
     * composite of first_name/middle_name/last_name via the User accessor.
     *
     * @return array<string,string>
     */
    public function availableColumns(): array
    {
        return [
            'name' => __('Naam'),
            'email' => __('E-mailadres'),
            'internal_id' => __('Intern ID'),
            'phone' => __('Telefoon'),
            'address' => __('Adres'),
            'start_date' => __('Startdatum'),
            'end_date' => __('Einddatum'),
            'status' => __('Status'),
            'locale' => __('Taal'),
        ];
    }

    public function users()
    {
        $query = User::query()->orderBy('last_name')->orderBy('first_name');
        if ($this->statusFilter !== '') {
            $query->where('status', $this->statusFilter);
        }

        return $query->get();
    }

    public function updatedSelectedColumns(): void
    {
        $valid = array_keys($this->availableColumns());
        $this->selectedColumns = array_values(array_intersect($valid, $this->selectedColumns));
    }

    #[Layout('components.layouts.app')]
    #[Title('Gebruikers')]
    public function render()
    {
        $this->authorize('viewAny', User::class);

        return view('livewire.users.index', [
            'users' => $this->users(),
            'columns' => $this->availableColumns(),
        ]);
    }
}
