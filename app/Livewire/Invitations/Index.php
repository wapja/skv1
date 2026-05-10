<?php

namespace App\Livewire\Invitations;

use App\Models\Invitation;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Session;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public const PER_PAGE_OPTIONS = [5, 10, 25, 50, 100];

    public const SORTABLE = [
        'email', 'name', 'status', 'inviter', 'expires_at', 'sent_at',
    ];

    public const STATUSES = ['pending', 'accepted', 'expired', 'cancelled'];

    public const DEFAULT_FILTERS = [
        'email' => '', 'name' => '', 'status' => '',
        'inviter' => '', 'expires_at' => '', 'sent_at' => '',
    ];

    /** @var array<string,string> */
    #[Session]
    public array $filters = self::DEFAULT_FILTERS;

    #[Session]
    public ?string $sortColumn = null;

    #[Session]
    public string $sortDirection = 'asc';

    #[Session]
    public int $perPage = 10;

    /** @var array<int,string> */
    #[Session]
    public array $selectedColumns = ['email', 'name', 'status'];

    /** @return array<string,string> */
    public function availableColumns(): array
    {
        return [
            'email'      => __('E-mailadres'),
            'name'       => __('Naam'),
            'status'     => __('Status'),
            'inviter'    => __('Uitgenodigd door'),
            'expires_at' => __('Verloopt op'),
            'sent_at'    => __('Verzonden op'),
        ];
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

    public function updatedPerPage(): void
    {
        if (! in_array($this->perPage, self::PER_PAGE_OPTIONS, true)) {
            $this->perPage = 10;
        }
        $this->resetPage();
    }

    public function invitations()
    {
        $query = Invitation::query()->with([
            'user' => fn ($q) => $q->withTrashed(),
            'inviter',
        ]);

        $this->applyFilters($query);
        $this->applySort($query);

        return $query->paginate($this->perPage);
    }

    protected function applyFilters(Builder $query): void
    {
        foreach ($this->filters as $key => $value) {
            if ($value === '' || $value === null) {
                continue;
            }
            match ($key) {
                'email' => $query->whereHas('user', fn ($q) => $q->withTrashed()
                    ->where('email', 'ILIKE', '%'.$value.'%')),

                'name' => $query->whereHas('user', function ($q) use ($value) {
                    $like = '%'.$value.'%';
                    $q->withTrashed()->where(fn ($qq) => $qq
                        ->where('first_name',  'ILIKE', $like)
                        ->orWhere('middle_name', 'ILIKE', $like)
                        ->orWhere('last_name', 'ILIKE', $like)
                    );
                }),

                'inviter' => $query->whereHas('inviter', fn ($q) => $q
                    ->where('email', 'ILIKE', '%'.$value.'%')),

                default => null, // andere arms volgen in Tasks 4-5
            };
        }
    }

    protected function applySort(Builder $query): void
    {
        if ($this->sortColumn === null) {
            $query->orderByDesc('invitations.created_at');
            return;
        }

        $direction = $this->sortDirection === 'desc' ? 'desc' : 'asc';

        match ($this->sortColumn) {
            'sent_at'    => $query->orderBy('invitations.created_at', $direction),
            'expires_at' => $query->orderBy('invitations.expires_at', $direction),
            default      => null,
        };
    }

    #[Layout('components.layouts.app')]
    #[Title('Uitgenodigde gebruikers')]
    public function render()
    {
        abort_unless(auth()->user()?->can('invitations.send'), 403);

        return view('livewire.invitations.index', [
            'invitations' => $this->invitations(),
            'columns' => $this->availableColumns(),
        ]);
    }
}
