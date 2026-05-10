<?php

use App\Models\Invitation;
use App\Models\User;
use App\Services\InvitationService;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Session;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public const PER_PAGE_OPTIONS = [5, 10, 25, 50, 100];

    public const SORTABLE = [
        'email', 'name', 'status', 'inviter', 'expires_at', 'sent_at',
    ];

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

    public function status(Invitation $invitation): string
    {
        return $invitation->status;
    }

    /** @return array<string,string> */
    public function availableColumns(): array
    {
        return [
            'email' => __('E-mailadres'),
            'name' => __('Naam'),
            'status' => __('Status'),
            'inviter' => __('Uitgenodigd door'),
            'expires_at' => __('Verloopt op'),
            'sent_at' => __('Verzonden op'),
        ];
    }

    /** @return array<int,int> */
    public function perPageOptions(): array
    {
        return self::PER_PAGE_OPTIONS;
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

    public function updatedFilters(): void
    {
        $valid = array_keys(self::DEFAULT_FILTERS);
        $this->filters = array_intersect_key(
            array_merge(self::DEFAULT_FILTERS, $this->filters),
            array_flip($valid),
        );

        if (! in_array($this->filters['status'], array_merge([''], Invitation::STATUSES), true)) {
            $this->filters['status'] = '';
        }

        $this->resetPage();
    }

    public function updatedSelectedColumns(): void
    {
        $valid = array_keys($this->availableColumns());
        $this->selectedColumns = array_values(array_intersect($valid, $this->selectedColumns));

        foreach (array_keys($this->filters) as $key) {
            if (! in_array($key, $this->selectedColumns, true)) {
                $this->filters[$key] = '';
            }
        }
    }

    public function updatedPerPage(): void
    {
        if (! in_array($this->perPage, self::PER_PAGE_OPTIONS, true)) {
            $this->perPage = 10;
        }
        $this->resetPage();
    }

    public function cancel(int $invitationId, InvitationService $service): void
    {
        abort_unless(auth()->user()?->can('invitations.cancel'), 403);

        $invitation = Invitation::findOrFail($invitationId);
        $service->cancel($invitation, auth()->user());

        $this->dispatch('invitation-cancelled');
    }

    public function resend(int $invitationId, InvitationService $service): void
    {
        abort_unless(auth()->user()?->can('invitations.send'), 403);

        $invitation = Invitation::findOrFail($invitationId);
        $service->resendReminder($invitation, auth()->user());
    }

    #[On('invitation-sent')]
    #[On('invitation-cancelled')]
    public function refresh(): void {}

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
                'email' => $query->whereHas('user', fn ($q) => $q->withTrashed()->whereEmailLike($value)),
                'name' => $query->whereHas('user', fn ($q) => $q->withTrashed()->whereNameLike($value)),
                'inviter' => $query->whereHas('inviter', fn ($q) => $q->whereEmailLike($value)),

                'status' => $query->whereStatus($value),

                'expires_at' => $query->whereDate('invitations.expires_at', '>=', $value),
                'sent_at' => $query->whereDate('invitations.created_at', '>=', $value),

                default => null,
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
            'sent_at' => $query->orderBy('invitations.created_at', $direction),
            'expires_at' => $query->orderBy('invitations.expires_at', $direction),

            'email' => $query->orderBy(
                User::withTrashed()->select('email')
                    ->whereColumn('users.id', 'invitations.user_id'),
                $direction
            ),

            'name' => $query
                ->orderBy(User::withTrashed()->select('last_name')->whereColumn('users.id', 'invitations.user_id'), $direction)
                ->orderBy(User::withTrashed()->select('first_name')->whereColumn('users.id', 'invitations.user_id'), $direction),

            'inviter' => $query->orderBy(
                User::query()->select('email')
                    ->whereColumn('users.id', 'invitations.invited_by'),
                $direction
            ),

            'status' => $query->orderByStatus($direction),

            default => null,
        };
    }

    public function clearFilters(): void
    {
        $this->filters = self::DEFAULT_FILTERS;
        $this->resetPage();
    }

    public function hasNoFilters(): bool
    {
        return collect($this->filters)
            ->filter(fn ($v) => $v !== '' && $v !== null)
            ->isEmpty();
    }

    #[Layout('components.layouts.app')]
    #[Title('Uitgenodigde gebruikers')]
    public function render()
    {
        abort_unless(auth()->user()?->can('invitations.send'), 403);

        return $this->view([
            'invitations' => $this->invitations(),
            'columns' => $this->availableColumns(),
        ]);
    }
};
