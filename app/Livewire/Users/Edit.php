<?php

namespace App\Livewire\Users;

use App\Http\Requests\UpdateUserRequest;
use App\Models\Organisation;
use App\Models\User;
use App\Services\UserRoleSyncer;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Spatie\Permission\PermissionRegistrar;

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

    public array $roles = [];

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

        $this->roles = $this->loadCurrentRoles($user);
    }

    /**
     * @return array<string,string> internal role-name => translated label
     */
    public function availableRoles(): array
    {
        $base = [
            'organisation_admin' => __('Organisatie-admin'),
            'test1' => __('Test rol 1'),
            'test2' => __('Test rol 2'),
        ];

        if (auth()->user()?->isSuperAdmin()) {
            return ['super_admin' => __('Super-admin')] + $base;
        }

        return $base;
    }

    protected function rules(): array
    {
        return (new UpdateUserRequest)->rules();
    }

    public function save(): mixed
    {
        $this->authorize('update', $this->user);

        $validated = $this->validate();

        $this->validate([
            'roles' => ['array'],
            'roles.*' => ['required', 'string', 'in:'.implode(',', array_keys($this->availableRoles()))],
        ]);

        DB::transaction(function () use ($validated) {
            $this->user->update($validated);

            $primaryOrgId = $this->user->organisation_id
                ?: Organisation::orderBy('id')->value('id');

            app(UserRoleSyncer::class)->sync($this->user, $this->roles, (int) $primaryOrgId);
        });

        session()->flash('status', __('Gebruiker opgeslagen.'));

        return redirect()->route('users.index');
    }

    /**
     * @return array<int,string>
     */
    protected function loadCurrentRoles(User $user): array
    {
        $registrar = app(PermissionRegistrar::class);
        $previousTeamId = $registrar->getPermissionsTeamId();

        try {
            $primaryOrgId = $user->organisation_id
                ?: Organisation::orderBy('id')->value('id');

            $current = [];

            if ($primaryOrgId !== null) {
                $registrar->setPermissionsTeamId((int) $primaryOrgId);
                $current = $user->getRoleNames()->all();
            }

            if ($user->isSuperAdmin()) {
                $current[] = 'super_admin';
            }

            return array_values(array_unique($current));
        } finally {
            $registrar->setPermissionsTeamId($previousTeamId);
        }
    }

    #[Layout('components.layouts.app')]
    #[Title('Gebruiker bewerken')]
    public function render()
    {
        return view('livewire.users.edit');
    }
}
