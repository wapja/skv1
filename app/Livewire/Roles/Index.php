<?php

namespace App\Livewire\Roles;

use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

class Index extends Component
{
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

    #[Layout('components.layouts.app')]
    #[Title('Rollen en permissies')]
    public function render()
    {
        $this->authorize('viewAny', Role::class);

        return view('livewire.roles.index', [
            'roles' => $this->roles(),
        ]);
    }
}
