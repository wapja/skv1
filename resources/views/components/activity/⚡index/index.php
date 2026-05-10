<?php

use App\Models\User;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Spatie\Activitylog\Models\Activity;

new class extends Component
{
    use WithPagination;

    #[Url(as: 'log')]
    public string $logFilter = '';

    #[Url(as: 'actor')]
    public ?int $actorFilter = null;

    #[Url(as: 'from')]
    public string $fromDate = '';

    #[Url(as: 'to')]
    public string $toDate = '';

    public function updated(): void
    {
        $this->resetPage();
    }

    protected function activitiesQuery()
    {
        $query = Activity::query()
            ->with(['causer', 'subject'])
            ->latest('id');

        if (! auth()->user()->isSuperAdmin()) {
            $orgUserIds = User::withoutTenantScope()
                ->where('organisation_id', auth()->user()->organisation_id)
                ->pluck('id');

            $query->where(function ($q) use ($orgUserIds) {
                $q->whereIn('causer_id', $orgUserIds)
                    ->orWhereIn('subject_id', $orgUserIds);
            });
        }

        if ($this->logFilter !== '') {
            $query->where('log_name', $this->logFilter);
        }

        if ($this->actorFilter !== null) {
            $query->where('causer_id', $this->actorFilter);
        }

        if ($this->fromDate !== '') {
            $query->where('created_at', '>=', $this->fromDate);
        }

        if ($this->toDate !== '') {
            $query->where('created_at', '<=', $this->toDate.' 23:59:59');
        }

        return $query;
    }

    public function actorOptions()
    {
        $query = User::withoutTenantScope()->orderBy('email');

        if (! auth()->user()->isSuperAdmin()) {
            $query->where('organisation_id', auth()->user()->organisation_id);
        }

        return $query->get(['id', 'email']);
    }

    public function logOptions(): array
    {
        return Activity::query()
            ->whereNotNull('log_name')
            ->distinct()
            ->orderBy('log_name')
            ->pluck('log_name')
            ->all();
    }

    #[Layout('components.layouts.app')]
    #[Title('Activiteitenlog')]
    public function render()
    {
        abort_unless(auth()->user()?->can('activity.view'), 403);

        return $this->view([
            'activities' => $this->activitiesQuery()->paginate(20),
            'actors' => $this->actorOptions(),
            'logs' => $this->logOptions(),
        ]);
    }
};
