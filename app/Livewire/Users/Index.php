<?php

namespace App\Livewire\Users;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Session;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public const PER_PAGE_OPTIONS = [5, 10, 25, 50, 100];

    public const SORTABLE = [
        'name', 'email', 'internal_id', 'phone', 'address',
        'start_date', 'end_date', 'status', 'locale',
    ];

    public const DEFAULT_FILTERS = [
        'name' => '', 'email' => '', 'internal_id' => '',
        'phone' => '', 'address' => '', 'start_date' => '',
        'end_date' => '', 'status' => '', 'locale' => '',
    ];

    /** @var array<string,string> */
    #[Session]
    public array $filters = self::DEFAULT_FILTERS;

    #[Session]
    public ?string $sortColumn = null;

    #[Session]
    public string $sortDirection = 'asc';

    #[Url(as: 'status')]
    public string $statusFilter = '';

    #[Session]
    public int $perPage = 10;

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
        $query = User::query();
        $this->applyFilters($query);
        $this->applySort($query);

        return $query->paginate($this->perPage);
    }

    public function updatedSelectedColumns(): void
    {
        $valid = array_keys($this->availableColumns());
        $this->selectedColumns = array_values(array_intersect($valid, $this->selectedColumns));
    }

    public function updatedPerPage(): void
    {
        if (! in_array($this->perPage, self::PER_PAGE_OPTIONS, true)) {
            $this->perPage = 10;
        }
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function sort(string $column): void
    {
        if (! in_array($column, self::SORTABLE, true)) {
            return;
        }
        if ($this->sortColumn !== $column) {
            $this->sortColumn = $column;
            $this->sortDirection = 'asc';
        } elseif ($this->sortDirection === 'asc') {
            $this->sortDirection = 'desc';
        } else {
            $this->sortColumn = null;
            $this->sortDirection = 'asc';
        }
        $this->resetPage();
    }

    public function updatedSortColumn(): void
    {
        if ($this->sortColumn !== null && ! in_array($this->sortColumn, self::SORTABLE, true)) {
            $this->sortColumn = null;
            $this->sortDirection = 'asc';
        }
    }

    protected function applyFilters(Builder $query): void
    {
        foreach ($this->filters as $key => $value) {
            if ($value === '' || $value === null) {
                continue;
            }
            match ($key) {
                'email', 'internal_id', 'phone', 'address'
                    => $query->where($key, 'ILIKE', '%' . $value . '%'),
                default => null,
            };
        }
    }

    protected function applySort(Builder $query): void
    {
        if ($this->sortColumn === null) {
            $query->orderBy('last_name')->orderBy('first_name');
            return;
        }
        $direction = $this->sortDirection === 'desc' ? 'desc' : 'asc';
        match ($this->sortColumn) {
            'name'  => $query->orderBy('last_name', $direction)
                             ->orderBy('first_name', $direction),
            default => $query->orderBy($this->sortColumn, $direction),
        };
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
