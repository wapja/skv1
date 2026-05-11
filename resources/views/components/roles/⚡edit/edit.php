<?php

use App\Models\Organisation;
use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Spatie\Permission\Models\Permission;

new class extends Component
{
    public ?Role $role = null;

    public string $name = '';

    public array $selectedPermissions = [];

    public ?int $organisationId = null;

    public function mount(?Role $role = null): void
    {
        if ($role && $role->exists) {
            $this->authorize('update', $role);

            $this->role = $role;
            $this->name = $role->name;
            $this->selectedPermissions = $role->permissions->pluck('id')->all();
            $this->organisationId = $role->team_id;
        } else {
            $this->authorize('create', Role::class);
        }
    }

    public function save(): mixed
    {
        if ($this->role) {
            $this->authorize('update', $this->role);
        } else {
            $this->authorize('create', Role::class);
        }

        $nameUnchanged = $this->role !== null && $this->name === $this->role->name;
        $isSuperAdmin = auth()->user()?->isSuperAdmin() ?? false;
        $wantsMove = $this->role !== null
            && $isSuperAdmin
            && $this->role->team_id !== null
            && $this->organisationId !== null
            && (int) $this->organisationId !== (int) $this->role->team_id;

        $targetTeamId = $wantsMove
            ? (int) $this->organisationId
            : ($this->role !== null ? $this->role->team_id : tenant()?->id);

        $this->validate([
            'name' => [
                'required', 'string', 'max:255', 'alpha_dash',
                Rule::unique('roles')
                    ->where(fn ($q) => $q
                        ->where('guard_name', 'web')
                        ->where('team_id', $targetTeamId))
                    ->ignore($this->role?->id),
                $nameUnchanged ? null : Rule::notIn(['super_admin', 'organisation_admin', 'member']),
                function ($attribute, $value, $fail) use ($nameUnchanged): void {
                    if ($nameUnchanged) {
                        return;
                    }
                    $clash = Role::query()
                        ->whereNull('team_id')
                        ->where('name', $value)
                        ->where('guard_name', 'web')
                        ->exists();
                    if ($clash) {
                        $fail(__('Deze naam is gereserveerd voor een rol zonder organisatie.'));
                    }
                },
            ],
            'selectedPermissions' => 'array',
            'selectedPermissions.*' => 'integer|exists:permissions,id',
            'organisationId' => [
                'nullable', 'integer', Rule::exists('organisations', 'id'),
                function ($attribute, $value, $fail) use ($wantsMove): void {
                    if (! $wantsMove) {
                        return;
                    }
                    $usersCount = DB::table('model_has_roles')
                        ->where('role_id', $this->role->id)
                        ->count();
                    if ($usersCount > 0) {
                        $fail(__('Verplaatsen geblokkeerd: er zijn nog gebruikers aan deze rol gekoppeld.'));
                    }
                },
            ],
        ]);

        $wasCreate = $this->role === null;

        DB::transaction(function () use ($wantsMove, $targetTeamId) {
            if ($this->role) {
                $update = ['name' => $this->name];
                if ($wantsMove) {
                    $update['team_id'] = $targetTeamId;
                }
                $this->role->update($update);
            } else {
                $this->role = Role::create([
                    'name' => $this->name,
                    'guard_name' => 'web',
                    'team_id' => tenant()?->id,
                ]);
            }

            // selectedPermissions arrive as string-cast ints from Livewire's wire-properties; resolve to names before syncing.
            $perms = Permission::whereIn('id', $this->selectedPermissions)->pluck('name');
            $this->role->syncPermissions($perms);
        });

        session()->flash('status', __($wasCreate ? 'Rol aangemaakt.' : 'Rol bijgewerkt.'));

        return redirect()->route('roles.index');
    }

    #[Layout('components.layouts.app')]
    #[Title('Rol bewerken')]
    public function render()
    {
        return $this->view([
            'permissions' => Permission::orderBy('name')->get(),
            'organisations' => Organisation::orderBy('name')->get(),
        ]);
    }
};
