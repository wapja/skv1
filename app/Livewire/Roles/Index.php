<?php

namespace App\Livewire\Roles;

use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Spatie\Permission\Models\Permission;

class Index extends Component
{
    #[Validate('required|string|max:255|alpha_dash')]
    public string $newRoleName = '';

    #[Validate('array')]
    public array $newRolePermissions = [];

    public function createRole(): void
    {
        $this->authorize('create', Role::class);
        $this->validate();

        $role = Role::create([
            'name' => $this->newRoleName,
            'guard_name' => 'web',
            'team_id' => tenant()?->id,
        ]);

        if (! empty($this->newRolePermissions)) {
            $perms = Permission::whereIn('id', $this->newRolePermissions)->pluck('name');
            $role->syncPermissions($perms);
        }

        $this->reset(['newRoleName', 'newRolePermissions']);
        session()->flash('status', __('Rol aangemaakt.'));
    }

    public function deleteRole(int $roleId): void
    {
        $role = Role::findOrFail($roleId);
        $this->authorize('delete', $role);

        if ($role->users()->count() > 0) {
            session()->flash('error', __('Rol is nog gekoppeld aan gebruikers.'));

            return;
        }

        $role->delete();
        session()->flash('status', __('Rol verwijderd.'));
    }

    public function roles()
    {
        $tenantId = tenant()?->id;

        return Role::query()
            ->where(function ($q) use ($tenantId) {
                $q->where('team_id', $tenantId)
                    ->orWhere(function ($q2) use ($tenantId) {
                        $q2->whereNull('team_id')
                            ->whereNotExists(function ($sub) use ($tenantId) {
                                $sub->select(DB::raw(1))
                                    ->from('roles as per_org')
                                    ->whereColumn('per_org.name', 'roles.name')
                                    ->where('per_org.team_id', $tenantId)
                                    ->whereNull('per_org.deleted_at');
                            });
                    });
            })
            ->with('permissions')
            ->withCount('users')
            ->orderBy('name')
            ->get();
    }

    public function allPermissions()
    {
        return Permission::orderBy('name')->get();
    }

    #[Layout('components.layouts.app')]
    #[Title('Rollen en permissies')]
    public function render()
    {
        $this->authorize('viewAny', Role::class);

        return view('livewire.roles.index', [
            'roles' => $this->roles(),
            'permissions' => $this->allPermissions(),
        ]);
    }
}
